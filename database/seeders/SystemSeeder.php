<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\SmsGateway;
use Illuminate\Database\Seeder;

/**
 * Platform integration defaults: mail settings and the provider catalogue.
 *
 * No credentials are seeded. A demo environment shipping with placeholder keys
 * that look real is how a test key ends up in production, so every provider
 * arrives switched off and empty.
 */
class SystemSeeder extends Seeder
{
    /** @return array<int, array<string, mixed>> */
    public static function mailSettings(): array
    {
        return [
            ['key' => 'mail.mailer', 'value' => 'log', 'type' => 'text', 'label' => 'Delivery', 'description' => 'Choose "Send with SMTP" once the server details below are correct.'],
            ['key' => 'mail.host', 'value' => '127.0.0.1', 'type' => 'text', 'label' => 'SMTP host'],
            ['key' => 'mail.port', 'value' => '2525', 'type' => 'number', 'label' => 'SMTP port'],
            ['key' => 'mail.username', 'value' => '', 'type' => 'text', 'label' => 'SMTP username'],
            ['key' => 'mail.password', 'value' => '', 'type' => 'text', 'label' => 'SMTP password', 'description' => 'Stored encrypted and never shown again. Leave blank to keep the current one.'],
            ['key' => 'mail.encryption', 'value' => 'tls', 'type' => 'text', 'label' => 'Encryption'],
            ['key' => 'mail.from_address', 'value' => 'hello@demo.test', 'type' => 'text', 'label' => 'From address'],
            ['key' => 'mail.from_name', 'value' => 'Dating App', 'type' => 'text', 'label' => 'From name'],
        ];
    }

    /**
     * Firebase credentials for push, empty until an operator fills them in.
     *
     * Kept out of .env so push can be switched on from the console. The
     * service account JSON is stored encrypted; the web values are public by
     * design — the browser SDK needs them in page source.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pushSettings(): array
    {
        return [
            ['key' => 'push.enabled', 'value' => '0', 'type' => 'boolean', 'label' => 'Push notifications', 'description' => 'Send notifications to phones and browsers through Firebase.'],
            ['key' => 'push.service_account', 'value' => '', 'type' => 'textarea', 'label' => 'Service account JSON', 'description' => 'Firebase console → Project settings → Service accounts → Generate new private key. Stored encrypted and never shown again.'],
            ['key' => 'push.project_id', 'value' => '', 'type' => 'text', 'label' => 'Firebase project'],
            ['key' => 'push.web_enabled', 'value' => '0', 'type' => 'boolean', 'label' => 'Browser notifications', 'description' => 'Let members turn on notifications in the web app.'],
            ['key' => 'push.web_api_key', 'value' => '', 'type' => 'text', 'label' => 'Web apiKey'],
            ['key' => 'push.web_auth_domain', 'value' => '', 'type' => 'text', 'label' => 'Web authDomain'],
            ['key' => 'push.web_project_id', 'value' => '', 'type' => 'text', 'label' => 'Web projectId'],
            ['key' => 'push.web_sender_id', 'value' => '', 'type' => 'text', 'label' => 'Web messagingSenderId'],
            ['key' => 'push.web_app_id', 'value' => '', 'type' => 'text', 'label' => 'Web appId'],
            ['key' => 'push.web_vapid_key', 'value' => '', 'type' => 'text', 'label' => 'Web push certificate (VAPID key pair)'],
        ];
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> */
    private const PAYMENT_GATEWAYS = [
        ['stripe', 'Stripe', 'USD,INR,EUR,GBP'],
        ['razorpay', 'Razorpay', 'INR'],
        ['payu', 'PayU', 'INR,USD'],
        ['paypal', 'PayPal', 'USD,INR,EUR,GBP'],
    ];

    /** @return array<int, array{0: string, 1: string}> */
    private const SMS_GATEWAYS = [
        ['twilio', 'Twilio'],
        ['msg91', 'MSG91'],
        ['vonage', 'Vonage'],
        ['textlocal', 'Textlocal'],
    ];

    public function run(): void
    {
        foreach (self::pushSettings() as $index => $setting) {
            Setting::query()->updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => Setting::query()->where('key', $setting['key'])->value('value') ?? $setting['value'],
                    'type' => $setting['type'],
                    'group' => 'push',
                    'label' => $setting['label'],
                    'description' => $setting['description'] ?? null,
                    'is_public' => false,
                    'sort_order' => $index,
                ],
            );
        }

        foreach (self::mailSettings() as $index => $setting) {
            Setting::query()->updateOrCreate(
                ['key' => $setting['key']],
                [
                    // Value is not overwritten on re-run: an operator's real
                    // SMTP configuration must survive a reseed of the catalogue.
                    'value' => Setting::query()->where('key', $setting['key'])->value('value') ?? $setting['value'],
                    'type' => $setting['type'],
                    'group' => 'mail',
                    'label' => $setting['label'],
                    'description' => $setting['description'] ?? null,
                    'is_public' => false,
                    'sort_order' => $index,
                ],
            );
        }

        foreach (self::PAYMENT_GATEWAYS as $index => [$slug, $name, $currencies]) {
            PaymentGateway::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'supported_currencies' => $currencies,
                    'sort_order' => $index,
                ],
            );
        }

        foreach (self::SMS_GATEWAYS as $index => [$slug, $name]) {
            SmsGateway::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'sort_order' => $index],
            );
        }

        $this->command?->info(sprintf(
            'Seeded %d mail settings, %d payment gateways and %d SMS gateways.',
            count(self::mailSettings()),
            count(self::PAYMENT_GATEWAYS),
            count(self::SMS_GATEWAYS),
        ));
    }
}
