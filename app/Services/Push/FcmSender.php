<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\AppUser;
use App\Models\PushLog;
use App\Models\PushToken;
use App\Support\PushSettings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sending through Firebase Cloud Messaging (HTTP v1).
 *
 * Talks to the REST API directly rather than pulling in the Firebase SDK:
 * the whole integration is one OAuth exchange and one POST per token, and a
 * vendor SDK would be a much larger surface to keep current for that.
 *
 * Note for anyone holding an old Firebase "server key": that API was switched
 * off by Google. v1 authenticates with a service account, which is why the
 * settings screen asks for the JSON file.
 */
final class FcmSender
{
    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Errors that mean the token is dead and should be deleted, not retried. */
    private const DEAD_TOKEN_CODES = ['UNREGISTERED', 'SENDER_ID_MISMATCH'];

    public function isConfigured(): bool
    {
        return PushSettings::enabled();
    }

    /**
     * Send to every device a member has registered.
     *
     * @return array{sent: int, failed: int, skipped: bool}
     */
    public function sendToMember(AppUser $member, PushMessage $message, ?int $campaignId = null): array
    {
        $tokens = PushToken::query()->where('app_user_id', $member->id)->get();

        if ($tokens->isEmpty() || ! $this->isConfigured()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true];
        }

        $sent = 0;
        $failed = 0;
        $lastError = null;

        foreach ($tokens as $token) {
            $result = $this->sendToToken($token, $message);

            if ($result['ok']) {
                $sent++;
            } else {
                $failed++;
                $lastError = $result['error'];
            }
        }

        // One log row per member per message, not per device: the delivery log
        // is read as "did this person get it", and three rows for somebody
        // with a phone and two browsers reads as three people.
        PushLog::query()->create([
            'push_campaign_id' => $campaignId,
            'app_user_id' => $member->id,
            'title' => $message->title,
            'status' => $sent > 0 ? 'sent' : 'failed',
            'failure_reason' => $sent > 0 ? null : ($lastError ?? 'No device accepted the message'),
            'sent_at' => $sent > 0 ? now() : null,
        ]);

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => false];
    }

    /**
     * Send to one registered device.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function sendToToken(PushToken $token, PushMessage $message): array
    {
        $result = $this->post($token->token, $message);

        if ($result['ok']) {
            $token->forceFill(['last_used_at' => now(), 'failure_count' => 0, 'last_failed_at' => null])->save();

            return ['ok' => true, 'error' => null];
        }

        if (in_array($result['code'], self::DEAD_TOKEN_CODES, true)) {
            // The app was uninstalled, the browser cleared its permission, or
            // the token belongs to another Firebase project. Keeping it would
            // mean failing for ever on every future send.
            $token->delete();

            return ['ok' => false, 'error' => 'Device no longer registered'];
        }

        $token->forceFill([
            'failure_count' => $token->failure_count + 1,
            'last_failed_at' => now(),
        ])->save();

        return ['ok' => false, 'error' => $result['error']];
    }

    /** A dry run against Firebase: proves the credentials without notifying anybody. */
    public function verifyCredentials(): array
    {
        $account = PushSettings::serviceAccount();

        if ($account === null) {
            return ['ok' => false, 'error' => 'No service account file has been saved yet.'];
        }

        $accessToken = $this->accessToken(true);

        if ($accessToken === null) {
            return ['ok' => false, 'error' => 'Firebase refused these credentials. Check that the JSON is the private key file for this project and has not been revoked.'];
        }

        return ['ok' => true, 'error' => null, 'project' => $account['project_id']];
    }

    /**
     * @return array{ok: bool, code: ?string, error: ?string}
     */
    private function post(string $token, PushMessage $message, bool $retryOnAuthFailure = true): array
    {
        $project = PushSettings::projectId();
        $accessToken = $this->accessToken();

        if ($project === null || $accessToken === null) {
            return ['ok' => false, 'code' => 'NOT_CONFIGURED', 'error' => 'Push is not configured.'];
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->asJson()
                ->post(sprintf(self::SEND_URL, $project), $message->toFcm($token, $this->absoluteLink($message)));
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 'UNAVAILABLE', 'error' => str($e->getMessage())->limit(180)->toString()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'code' => null, 'error' => null];
        }

        // An expired access token: mint a fresh one and try once more.
        if ($response->status() === 401 && $retryOnAuthFailure) {
            Cache::forget($this->tokenCacheKey());

            return $this->post($token, $message, retryOnAuthFailure: false);
        }

        return ['ok' => false, 'code' => $this->errorCode($response), 'error' => $this->errorMessage($response)];
    }

    /** Where a tap should land, as an absolute URL Firebase will accept. */
    private function absoluteLink(PushMessage $message): string
    {
        if ($message->link === null) {
            return url('/');
        }

        return str_starts_with($message->link, 'http') ? $message->link : url($message->link);
    }

    /**
     * A Google access token for the service account.
     *
     * Cached just under its hour, because minting one is a signature plus a
     * round-trip and a campaign to ten thousand people would otherwise pay
     * that ten thousand times.
     */
    private function accessToken(bool $fresh = false): ?string
    {
        if ($fresh) {
            Cache::forget($this->tokenCacheKey());
        }

        return Cache::remember($this->tokenCacheKey(), now()->addMinutes(55), function (): ?string {
            $account = PushSettings::serviceAccount();

            if ($account === null) {
                return null;
            }

            $assertion = $this->signedAssertion($account);

            if ($assertion === null) {
                return null;
            }

            try {
                $response = Http::asForm()->timeout(15)->post($account['token_uri'] ?? self::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
            } catch (Throwable $e) {
                Log::warning('Could not reach Google for an FCM access token: '.$e->getMessage());

                return null;
            }

            if (! $response->successful()) {
                Log::warning('Google refused the FCM service account: '.$response->body());

                return null;
            }

            return $response->json('access_token');
        });
    }

    /** The signed JWT that Google exchanges for an access token. */
    private function signedAssertion(array $account): ?string
    {
        $now = time();

        $segments = [
            $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url(json_encode([
                'iss' => $account['client_email'],
                'scope' => self::SCOPE,
                'aud' => $account['token_uri'] ?? self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $signature = '';
        $key = openssl_pkey_get_private($account['private_key']);

        if ($key === false) {
            Log::warning('The FCM service account private key could not be read.');

            return null;
        }

        if (! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function tokenCacheKey(): string
    {
        return 'platform.fcm.access_token.'.md5((string) PushSettings::clientEmail());
    }

    /** FCM puts its own code in error.details, not in the HTTP status. */
    private function errorCode(Response $response): ?string
    {
        foreach ($response->json('error.details') ?? [] as $detail) {
            if (isset($detail['errorCode'])) {
                return $detail['errorCode'];
            }
        }

        return $response->json('error.status');
    }

    private function errorMessage(Response $response): string
    {
        return match ($this->errorCode($response)) {
            'UNREGISTERED', 'SENDER_ID_MISMATCH' => 'Device no longer registered',
            'QUOTA_EXCEEDED' => 'Firebase rate limit reached — try again shortly',
            'THIRD_PARTY_AUTH_ERROR' => 'Firebase is missing the Apple push key or web certificate',
            'UNAUTHENTICATED', 'PERMISSION_DENIED' => 'Firebase rejected the service account',
            default => str((string) $response->json('error.message', 'Firebase refused the message'))->limit(180)->toString(),
        };
    }
}
