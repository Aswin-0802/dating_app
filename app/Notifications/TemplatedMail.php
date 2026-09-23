<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Branding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An email whose words come from a notification template.
 *
 * Deliberately thin: everything an operator can change lives in the template,
 * so adding an email means seeding a template, not writing a class.
 *
 * Queued, because talking to an SMTP server is the slowest thing in any
 * request that sends one, and a member should not wait for it. This needs a
 * worker running in production — see the deployment notes in the README. On
 * the sync driver (tests, and any install without a worker) it still runs
 * inline and behaves exactly as before.
 */
class TemplatedMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $subjectLine,
        private readonly string $body,
        private readonly ?string $actionText = null,
        private readonly ?string $actionUrl = null,
        /** Which template this came from — carried so it can be asserted on and logged. */
        public readonly string $templateKey = '',
        /** The email_logs row to settle once the send has actually happened. */
        public readonly ?int $emailLogId = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subjectLine);

        foreach (preg_split('/\R{2,}/', trim($this->body)) ?: [] as $paragraph) {
            $mail->line(trim($paragraph));
        }

        if ($this->actionUrl !== null) {
            $mail->action($this->actionText ?? 'Open '.Branding::name(), $this->actionUrl);
        }

        return $mail->salutation('The '.Branding::name().' team');
    }
}
