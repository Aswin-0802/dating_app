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
            ['key' => 'mail.host', 'value' => '127.0.0.1', 'type' => 'text', 'label' => 'SMTP host'],
            ['key' => 'mail.port', 'value' => '2525', 'type' => 'number', 'label' => 'SMTP port'],
            ['key' => 'mail.username', 'value' => '', 'type' => 'text', 'label' => 'SMTP username'],
            ['key' => 'mail.password', 'value' => '', 'type' => 'text', 'label' => 'SMTP password', 'description' => 'Stored encrypted and never shown again.'],
            ['key' => 'mail.encryption', 'value' => 'tls', 'type' => 'text', 'label' => 'Encryption', 'description' => 'tls, ssl, or blank for none.'],
            ['key' => 'mail.from_address', 'value' => 'hello@veyra.test', 'type' => 'text', 'label' => 'From address'],
            ['key' => 'mail.from_name', 'value' => 'Veyra', 'type' => 'text', 'label' => 'From name'],
        ];
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> */
    private const PAYMENT_GATEWAYS = [
        ['stripe', 'Stripe', 'GBP,USD,EUR'],
        ['razorpay', 'Razorpay', 'INR'],
        ['payu', 'PayU', 'INR,USD'],
        ['paypal', 'PayPal', 'GBP,USD,EUR'],
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
