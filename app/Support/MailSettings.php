<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Outgoing mail configured from System -> Mail rather than .env.
 *
 * Applied on every boot, so password resets, receipts and every other message
 * use the SMTP server an operator saved in the console — not only the test
 * message. `mail.mailer` chooses between really sending (`smtp`) and writing
 * messages to the log (`log`), which is the safe default on a fresh install.
 */
final class MailSettings
{
    public const MAILERS = ['smtp' => 'Send with SMTP', 'log' => 'Do not send (write to log)'];

    public const ENCRYPTION = ['tls' => 'TLS', 'ssl' => 'SSL', '' => 'None'];

    public static function apply(): void
    {
        try {
            $mailer = platform_setting('mail.mailer');

            if (! in_array($mailer, array_keys(self::MAILERS), true)) {
                return;
            }

            Config::set('mail.default', $mailer);
            Config::set('mail.mailers.smtp.host', platform_setting('mail.host'));
            Config::set('mail.mailers.smtp.port', (int) platform_setting('mail.port', 587));
            Config::set('mail.mailers.smtp.username', platform_setting('mail.username') ?: null);
            Config::set('mail.mailers.smtp.password', self::decrypt((string) platform_setting('mail.password', '')) ?: null);
            Config::set('mail.mailers.smtp.scheme', platform_setting('mail.encryption') === 'ssl' ? 'smtps' : null);

            if ($from = platform_setting('mail.from_address')) {
                Config::set('mail.from.address', $from);
            }

            Config::set('mail.from.name', platform_setting('mail.from_name') ?: Branding::name());
        } catch (Throwable) {
            // Settings table not migrated yet: keep the .env configuration.
        }
    }

    public static function encrypt(string $plain): string
    {
        return $plain === '' ? '' : Crypt::encryptString($plain);
    }

    /** Also accepts a legacy plain-text value, from before encryption. */
    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return $stored;
        }
    }
}
