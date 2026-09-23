<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Jobs\SendMemberPush;
use App\Models\AppUser;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sends a message and keeps the conversation and match counters in step.
 *
 * Shared by the website and the mobile API.
 */
final class MessageSender
{
    public function send(Conversation $conversation, AppUser $member, ?string $body, string $type = 'text', ?string $mediaPath = null): Message
    {
        $this->assertUsableBy($conversation, $member);

        return DB::transaction(function () use ($conversation, $member, $body, $type, $mediaPath): Message {
            $message = Message::query()->create([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'sender_app_user_id' => $member->id,
                'type' => $type,
                'body' => $body,
                'media_path' => $mediaPath,
                // Scanned on write, so the risk engine and the flagged-message
                // stream have something to read without reprocessing history.
                'contains_link' => $body !== null && ContentScanner::containsLink($body),
                'contains_contact_info' => $body !== null && ContentScanner::containsContactInfo($body),
                'created_at' => now(),
            ]);

            $isFirst = $conversation->messages_count === 0;

            /*
             * Incremented in SQL, not read-then-written.
             *
             * Two messages sent in the same moment both read the same count and
             * both wrote it back plus one, so the conversation permanently
             * under-counted — and the counter is what the admin list and the
             * "silent match" queue are built on.
             */
            $conversation->increment('messages_count', 1, [
                'last_message_at' => now(),
                'started_at' => $conversation->started_at ?? now(),
            ]);

            $match = $conversation->match;

            if ($match !== null) {
                $attributes = ['last_message_at' => now()];

                if ($isFirst) {
                    $attributes['first_message_at'] = now();
                }

                // A reply is the first message from the OTHER party. That is the
                // metric that matters — matches alone are vanity.
                if ($match->first_reply_at === null
                    && $match->first_message_at !== null
                    && ! $isFirst
                    && Message::query()
                        ->where('conversation_id', $conversation->id)
                        ->where('sender_app_user_id', '!=', $member->id)
                        ->exists()) {
                    $attributes['first_reply_at'] = now();
                }

                $match->increment('messages_count', 1, $attributes);
            }

            if ($member->first_message_at === null) {
                $member->forceFill(['first_message_at' => now()])->saveQuietly();
            }

            $this->notifyRecipient($conversation, $member);

            return $message;
        });
    }

    /**
     * Tell the other person they have a message.
     *
     * The template for this has been editable in the console since the
     * beginning and was referenced by nothing, so "you have a new message" was
     * never once delivered. Along with the new-match notification, this is the
     * loop a dating app actually runs on.
     *
     * Queued after commit: the member must not be told about a message that a
     * rollback then takes away, and Firebase must not be in the request.
     */
    private function notifyRecipient(Conversation $conversation, AppUser $sender): void
    {
        $recipient = $conversation->match?->otherParty($sender);

        if ($recipient === null) {
            return;
        }

        DB::afterCommit(fn () => SendMemberPush::dispatch(
            $recipient->id,
            'message.new',
            ['name' => $sender->display_name],
            route('member.messages', $conversation),
        ));
    }

    /**
     * May this member use this thread at all?
     *
     * Three separate rules, deliberately answered in one place. They used to
     * live in the Livewire component, which meant the website enforced them
     * and the mobile API did not.
     *
     * A block gets the same refusal as a closed thread rather than its own.
     * "You have been blocked" tells somebody exactly who to find another way
     * to reach, which on a dating app is the harm the block existed to stop.
     */
    public function assertUsableBy(Conversation $conversation, AppUser $member): void
    {
        abort_unless($conversation->hasParticipant($member), 403, 'This conversation is not yours.');
        abort_unless($conversation->status === 'open', 403, 'This conversation is closed.');
        abort_if($this->isBlockedPair($conversation, $member), 403, 'This conversation is closed.');
    }

    public function markRead(Conversation $conversation, AppUser $member): void
    {
        abort_unless($conversation->hasParticipant($member), 403, 'This conversation is not yours.');

        Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_app_user_id', '!=', $member->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * A block in either direction ends the conversation for both people.
     *
     * Closing the thread on block is the primary guard; this is the backstop
     * for a block written before that rule existed, or by any path that writes
     * a Block row directly.
     */
    private function isBlockedPair(Conversation $conversation, AppUser $member): bool
    {
        $other = $conversation->match?->otherParty($member);

        if ($other === null) {
            return false;
        }

        return Block::query()
            ->where(fn (QueryBuilder $q) => $q
                ->where('app_user_id', $member->id)
                ->where('blocked_app_user_id', $other->id))
            ->orWhere(fn (QueryBuilder $q) => $q
                ->where('app_user_id', $other->id)
                ->where('blocked_app_user_id', $member->id))
            ->exists();
    }
}
