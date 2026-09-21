<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\AppUser;
use App\Models\SmsGateway;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sending an SMS through whichever provider the operator switched on.
 *
 * Twilio, MSG91 and Vonage speak three different dialects of "send a text";
 * each is a handful of parameters, so they live here as three small methods
 * rather than three vendor SDKs. Every attempt is written to the SMS log,
 * because texts cost money and "did it send?" is a billing question.
 */
final class SmsSender
{
    public function isConfigured(): bool
    {
        return $this->gateway() !== null;
    }

    public function gateway(): ?SmsGateway
    {
        $gateway = SmsGateway::query()->active()->orderBy('sort_order')->first();

        return $gateway !== null && $gateway->isConfigured() ? $gateway : null;
    }

    /**
     * Send one message.
     *
     * @return bool false when there is no gateway, the number is unusable, or
     *              the provider refused it — the caller decides what to tell
     *              the member, and never that a text is on its way when it is not.
     */
    public function send(string $to, string $body, ?AppUser $member = null): bool
    {
        $gateway = $this->gateway();
        $to = $this->normalise($to);

        if ($gateway === null || $to === null) {
            $this->log($to ?? '', $body, 'unknown', 'failed', $gateway === null
                ? 'No SMS gateway is configured'
                : 'The number is not in a usable format', $member);

            return false;
        }

        try {
            $result = match ($gateway->slug) {
                'twilio' => $this->twilio($gateway, $to, $body),
                'msg91' => $this->msg91($gateway, $to, $body),
                'vonage' => $this->vonage($gateway, $to, $body),
                'textlocal' => $this->textlocal($gateway, $to, $body),
                default => ['ok' => false, 'error' => "No driver for {$gateway->name} yet"],
            };
        } catch (Throwable $e) {
            Log::warning("SMS through {$gateway->slug} failed: {$e->getMessage()}");
            $result = ['ok' => false, 'error' => str($e->getMessage())->limit(180)->toString()];
        }

        $this->log($to, $body, $gateway->slug, $result['ok'] ? 'sent' : 'failed', $result['error'] ?? null, $member);

        return $result['ok'];
    }

    /** @return array{ok: bool, error?: string} */
    private function twilio(SmsGateway $gateway, string $to, string $body): array
    {
        $sid = (string) ($gateway->credentials['account_sid'] ?? '');

        $response = Http::withBasicAuth($sid, (string) ($gateway->credentials['auth_token'] ?? ''))
            ->asForm()
            ->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $to,
                'From' => (string) ($gateway->credentials['from_number'] ?? ''),
                'Body' => $body,
            ]);

        return $response->successful()
            ? ['ok' => true]
            : ['ok' => false, 'error' => (string) $response->json('message', 'Twilio refused the message')];
    }

    /** @return array{ok: bool, error?: string} */
    private function msg91(SmsGateway $gateway, string $to, string $body): array
    {
        $response = Http::withHeaders(['authkey' => (string) ($gateway->credentials['auth_key'] ?? '')])
            ->timeout(15)
            ->post('https://control.msg91.com/api/v5/flow/', [
                'template_id' => (string) ($gateway->credentials['template_id'] ?? ''),
                'sender' => $gateway->sender_id ?: null,
                'recipients' => [['mobiles' => ltrim($to, '+'), 'body' => $body]],
            ]);

        $ok = $response->successful() && ($response->json('type') !== 'error');

        return $ok ? ['ok' => true] : ['ok' => false, 'error' => (string) $response->json('message', 'MSG91 refused the message')];
    }

    /** @return array{ok: bool, error?: string} */
    private function vonage(SmsGateway $gateway, string $to, string $body): array
    {
        $response = Http::asForm()->timeout(15)->post('https://rest.nexmo.com/sms/json', [
            'api_key' => (string) ($gateway->credentials['api_key'] ?? ''),
            'api_secret' => (string) ($gateway->credentials['api_secret'] ?? ''),
            'to' => ltrim($to, '+'),
            'from' => $gateway->sender_id ?: 'Veyra',
            'text' => $body,
        ]);

        $status = $response->json('messages.0.status');

        return $status === '0'
            ? ['ok' => true]
            : ['ok' => false, 'error' => (string) $response->json('messages.0.error-text', 'Vonage refused the message')];
    }

    /** @return array{ok: bool, error?: string} */
    private function textlocal(SmsGateway $gateway, string $to, string $body): array
    {
        $response = Http::asForm()->timeout(15)->post('https://api.textlocal.in/send/', [
            'apikey' => (string) ($gateway->credentials['api_key'] ?? ''),
            'numbers' => ltrim($to, '+'),
            'sender' => $gateway->sender_id ?: 'TXTLCL',
            'message' => $body,
        ]);

        return $response->json('status') === 'success'
            ? ['ok' => true]
            : ['ok' => false, 'error' => (string) $response->json('errors.0.message', 'Textlocal refused the message')];
    }

    /**
     * E.164, or nothing.
     *
     * Providers reject anything else, and a number stored with spaces or a
     * leading zero is the most common reason a text never arrives.
     */
    private function normalise(string $number): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $number) ?? '';

        if (str_starts_with($digits, '+')) {
            $digits = '+'.preg_replace('/\D/', '', substr($digits, 1));
        }

        return preg_match('/^\+?[1-9]\d{7,14}$/', $digits) === 1
            ? (str_starts_with($digits, '+') ? $digits : '+'.$digits)
            : null;
    }

    private function log(string $to, string $body, string $gateway, string $status, ?string $error, ?AppUser $member): void
    {
        try {
            SmsLog::query()->create([
                'to' => $to,
                // Stored as sent: an OTP in the log is how support proves what
                // a member was actually told.
                'body' => str($body)->limit(300)->toString(),
                'gateway' => $gateway,
                'status' => $status,
                'failure_reason' => $error,
                'segments' => (int) ceil(mb_strlen($body) / 160),
                'recipient_type' => $member?->getMorphClass(),
                'recipient_id' => $member?->id,
                'sent_at' => $status === 'sent' ? now() : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not write an SMS log row: '.$e->getMessage());
        }
    }
}
