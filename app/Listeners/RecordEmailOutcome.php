<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\EmailLog;
use App\Notifications\TemplatedMail;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Settles an email_logs row once the mail has actually been handed over.
 *
 * MemberNotifier records the row as `queued` before dispatching, because at
 * that point nothing has been sent. This is what turns it into `sent` or
 * `failed`, whether the queue ran it inline or a worker picked it up minutes
 * later.
 */
class RecordEmailOutcome
{
    public function sent(NotificationSent $event): void
    {
        $this->settle($event->notification, 'sent');
    }

    public function failed(NotificationFailed $event): void
    {
        $this->settle(
            $event->notification,
            'failed',
            is_string($event->data['message'] ?? null) ? $event->data['message'] : 'The mail server refused the message.',
        );
    }

    private function settle(object $notification, string $status, ?string $error = null): void
    {
        if (! $notification instanceof TemplatedMail || $notification->emailLogId === null) {
            return;
        }

        EmailLog::query()
            ->whereKey($notification->emailLogId)
            // Never walk a delivery outcome backwards: a bounce recorded later
            // is a newer fact than this event.
            ->where('status', 'queued')
            ->update([
                'status' => $status,
                'failure_reason' => $error === null ? null : str($error)->limit(180)->toString(),
                'sent_at' => $status === 'sent' ? now() : null,
            ]);
    }
}
