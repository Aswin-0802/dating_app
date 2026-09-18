<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\AppUser;
use App\Models\Conversation;
use App\Models\Message;
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
        abort_unless($conversation->hasParticipant($member), 403, 'This conversation is not yours.');

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

            $conversation->forceFill([
                'messages_count' => $conversation->messages_count + 1,
                'last_message_at' => now(),
                'started_at' => $conversation->started_at ?? now(),
            ])->save();

            $match = $conversation->match;

            if ($match !== null) {
                $attributes = [
                    'messages_count' => $match->messages_count + 1,
                    'last_message_at' => now(),
                ];

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

                $match->forceFill($attributes)->save();
            }

            if ($member->first_message_at === null) {
                $member->forceFill(['first_message_at' => now()])->saveQuietly();
            }

            return $message;
        });
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
}
