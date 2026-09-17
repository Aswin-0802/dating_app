<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAccessLog;
use App\Models\ReportCase;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;

/**
 * The only path by which staff can read member message content.
 *
 * The protection is structural rather than procedural: `body` is in Message's
 * $hidden, so a controller, a view or a JSON response cannot leak it by
 * accident. Reading it requires coming through here, which means:
 *
 *   1. holding `view_message_content`;
 *   2. naming a reason from a fixed list;
 *   3. writing a justification;
 *
 * and produces two immutable records — a message_access_log row and a
 * sensitive activity_log entry. Neither can be edited or deleted afterwards.
 *
 * This is what makes "we only read messages when we have to" an auditable claim
 * rather than a promise.
 */
final class MessageRevealService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * Reveal a conversation's messages.
     *
     * @return Collection<int, Message>
     *
     * @throws AuthorizationException
     */
    public function reveal(
        Conversation $conversation,
        User $actor,
        string $reasonCode,
        string $justification,
        ?ReportCase $case = null,
        ?Message $anchor = null,
    ): Collection {
        if (! $actor->can('view_message_content')) {
            throw new AuthorizationException('You do not have permission to read message content.');
        }

        $this->guardReason($reasonCode, $justification);

        $messages = $this->messagesFor($conversation, $anchor);

        MessageAccessLog::query()->create([
            'user_id' => $actor->id,
            'conversation_id' => $conversation->id,
            'message_id' => $anchor?->id,
            'report_case_id' => $case?->id,
            'reason_code' => $reasonCode,
            'justification' => $justification,
            'messages_revealed' => $messages->count(),
            'ip_address' => Request::ip(),
        ]);

        $this->logger->logSensitiveAccess(
            module: 'conversations',
            action: 'revealed_messages',
            subject: $conversation,
            description: sprintf(
                '%s read %d messages — %s',
                $actor->name,
                $messages->count(),
                $this->reasonLabel($reasonCode),
            ),
            context: [
                'reason_code' => $reasonCode,
                'justification' => $justification,
                'messages_revealed' => $messages->count(),
                'report_case_id' => $case?->id,
            ],
        );

        return $messages;
    }

    /**
     * Whether a reveal granted earlier is still in force.
     *
     * Reveals expire so a moderator who opened a conversation this morning does
     * not still have it open this afternoon. Re-reading means re-justifying.
     */
    public function hasActiveReveal(Conversation $conversation, User $actor): bool
    {
        $minutes = (int) veyra_setting(
            'privacy.message_reveal_minutes',
            config('veyra.privacy.message_reveal_minutes', 15),
        );

        return MessageAccessLog::query()
            ->where('user_id', $actor->id)
            ->where('conversation_id', $conversation->id)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }

    /**
     * @return Collection<int, Message>
     */
    private function messagesFor(Conversation $conversation, ?Message $anchor): Collection
    {
        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with('sender:id,display_name')
            ->orderBy('created_at');

        if ($anchor === null) {
            return $query->get();
        }

        // Anchored reveals return a window, not the whole history: reading ten
        // messages either side of a report is proportionate, reading two years
        // of somebody's private conversation is not.
        $window = (int) veyra_setting(
            'privacy.message_context_window',
            config('veyra.privacy.message_context_window', 10),
        );

        $all = $query->get();
        $index = $all->search(fn (Message $m): bool => $m->id === $anchor->id);

        return $index === false
            ? $all->take($window)
            : $all->slice(max(0, $index - $window), $window * 2 + 1)->values();
    }

    private function guardReason(string $reasonCode, string $justification): void
    {
        $reasons = config('veyra.privacy.reveal_reasons', []);

        if (! array_key_exists($reasonCode, $reasons)) {
            throw new InvalidArgumentException('Choose a valid reason for reading this conversation.');
        }

        if (! veyra_setting('privacy.require_justification', true)) {
            return;
        }

        // A justification short enough to be typed without thinking is not a
        // justification.
        if (mb_strlen(trim($justification)) < 10) {
            throw new InvalidArgumentException(
                'Write a justification of at least 10 characters. It is recorded against your name.',
            );
        }
    }

    private function reasonLabel(string $reasonCode): string
    {
        return config("veyra.privacy.reveal_reasons.{$reasonCode}", $reasonCode);
    }
}
