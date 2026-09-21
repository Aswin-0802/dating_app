<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Branding;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An email whose words come from a notification template.
 *
 * Deliberately thin: everything an operator can change lives in the template,
 * so adding an email means seeding a template, not writing a class.
 */
class TemplatedMail extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $subjectLine,
        private readonly string $body,
        private readonly ?string $actionText = null,
        private readonly ?string $actionUrl = null,
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
