<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Firebase credentials, held in settings rather than .env so push can be
 * turned on from the console without a deploy.
 *
 * Two halves, because Firebase needs both: a **service account** (the private
 * key this server signs with, to send) and the **web app config plus VAPID
 * key** (public values the browser needs, to receive). Mobile apps need only
 * the service account here — their own Firebase files ship inside the app.
 */
final class PushSettings
{
    /** The public Firebase web config, as the console's "Web app" panel names them. */
    public const WEB_FIELDS = [
        'web_api_key' => 'apiKey',
        'web_auth_domain' => 'authDomain',
        'web_project_id' => 'projectId',
        'web_sender_id' => 'messagingSenderId',
        'web_app_id' => 'appId',
    ];

    public static function enabled(): bool
    {
        return (bool) platform_setting('push.enabled', false) && self::hasServiceAccount();
    }

    public static function webEnabled(): bool
    {
        return self::enabled() && (bool) platform_setting('push.web_enabled', false) && self::webConfigured();
    }

    public static function hasServiceAccount(): bool
    {
        return self::serviceAccount() !== null;
    }

    /**
     * The decoded service account JSON, or null if it is missing or unusable.
     *
     * @return array{project_id: string, client_email: string, private_key: string, token_uri?: string}|null
     */
    public static function serviceAccount(): ?array
    {
        try {
            $stored = (string) platform_setting('push.service_account', '');
        } catch (Throwable) {
            return null;
        }

        if ($stored === '') {
            return null;
        }

        $json = self::decrypt($stored);
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (! isset($decoded[$key]) || ! is_string($decoded[$key]) || $decoded[$key] === '') {
                return null;
            }
        }

        return $decoded;
    }

    public static function projectId(): ?string
    {
        return self::serviceAccount()['project_id'] ?? null;
    }

    public static function clientEmail(): ?string
    {
        return self::serviceAccount()['client_email'] ?? null;
    }

    /**
     * Values the browser SDK needs. Public by design — they end up in page
     * source, which is how Firebase works; the private key never does.
     *
     * @return array<string, string>
     */
    public static function webConfig(): array
    {
        $config = [];

        foreach (self::WEB_FIELDS as $setting => $sdkKey) {
            $config[$sdkKey] = (string) platform_setting("push.{$setting}", '');
        }

        return $config;
    }

    public static function vapidKey(): string
    {
        return (string) platform_setting('push.web_vapid_key', '');
    }

    public static function webConfigured(): bool
    {
        return self::vapidKey() !== '' && ! in_array('', self::webConfig(), true);
    }

    /** Store the service account JSON, encrypted. Returns false if it is not usable. */
    public static function storeServiceAccount(string $json): bool
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ($decoded['type'] ?? null) !== 'service_account') {
            return false;
        }

        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (! isset($decoded[$key]) || ! is_string($decoded[$key]) || $decoded[$key] === '') {
                return false;
            }
        }

        Setting::put('push.service_account', Crypt::encryptString($json));
        Setting::put('push.project_id', $decoded['project_id']);

        return true;
    }

    public static function forgetServiceAccount(): void
    {
        Setting::put('push.service_account', '');
        Setting::put('push.project_id', '');
    }

    /** Accepts a legacy plain value, the same way mail settings do. */
    private static function decrypt(string $stored): string
    {
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return $stored;
        }
    }
}
