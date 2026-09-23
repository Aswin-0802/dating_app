<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\AppUser;
use App\Models\EmailLog;
use App\Models\NotificationTemplate;
use App\Notifications\TemplatedMail;
use App\Services\Push\FcmSender;
use App\Services\Push\PushMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sending a member something, in the operator's words.
 *
 * Every member-facing message goes through here so the wording always comes
 * from a template the operator can edit, and so a send that fails is recorded
 * rather than lost — "did they get the renewal warning?" has to be answerable
 * from the console.
 */
final class MemberNotifier
{
    public function __construct(private readonly FcmSender $push) {}

    /**
     * Push notification. Returns false when there was nothing to send to, or
     * push is not configured — both are normal, not errors.
     *
     * @param  array<string, string|int|null>  $values
     */
    public function push(AppUser $member, string $templateKey, array $values = [], ?string $link = null): bool
    {
        $template = $this->template($templateKey);

        if ($template === null || ! $this->push->isConfigured()) {
            return false;
        }

        $result = $this->push->sendToMember($member, new PushMessage(
            title: $this->render((string) $template->subject, $values),
            body: $this->render($template->body, $values),
            link: $link,
            data: ['template' => $templateKey] + array_map(fn ($v): string => (string) $v, $values),
        ));

        return $result['sent'] > 0;
    }

    /**
     * Email. Written to the email log either way, so the delivery log answers
     * for real sends and not only for demo rows.
     *
     * @param  array<string, string|int|null>  $values
     */
    public function email(AppUser $member, string $templateKey, array $values = [], ?string $actionText = null, ?string $actionUrl = null): bool
    {
        $template = $this->template($templateKey);

        if ($template === null) {
            return false;
        }

        $subject = $this->render((string) $template->subject, $values);
        $body = $this->render($template->body, $values);

        /*
         * The log row is written first, as `queued`, and settled to `sent` or
         * `failed` by RecordEmailOutcome when the send actually happens.
         *
         * Writing `sent` at this point would be a lie once the mail is queued:
         * all that has happened is that a job was accepted. "Did they get the
         * renewal warning?" has to be answerable from the console, so the log
         * has to describe delivery rather than intent.
         */
        $log = $this->log($member, $subject, $templateKey, 'queued');

        try {
            $member->notify(new TemplatedMail($subject, $body, $actionText, $actionUrl, $templateKey, $log?->id));
        } catch (Throwable $e) {
            $log?->forceFill([
                'status' => 'failed',
                'failure_reason' => str($e->getMessage())->limit(180)->toString(),
            ])->save();

            Log::warning("Could not email {$member->email}: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    private function template(string $key): ?NotificationTemplate
    {
        $template = NotificationTemplate::query()->where('key', $key)->where('is_active', true)->first();

        if ($template === null) {
            Log::warning("No active notification template for '{$key}'.");
        }

        return $template;
    }

    /** @param  array<string, string|int|null>  $values */
    private function render(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            $text = preg_replace('/\{\{\s*'.preg_quote((string) $key, '/').'\s*\}\}/', (string) $value, $text) ?? $text;
        }

        return $text;
    }

    private function log(AppUser $member, string $subject, string $templateKey, string $status, ?string $error = null): ?EmailLog
    {
        try {
            return EmailLog::query()->create([
                'to' => $member->email,
                'subject' => $subject,
                'template_key' => $templateKey,
                'status' => $status,
                'failure_reason' => $error,
                'recipient_type' => $member->getMorphClass(),
                'recipient_id' => $member->id,
                'sent_at' => $status === 'sent' ? now() : null,
            ]);
        } catch (Throwable $e) {
            // A logging failure must never swallow a message that was sent.
            Log::warning('Could not write an email log row: '.$e->getMessage());

            return null;
        }
    }
}
