<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The currency prices are shown and charged in.
 *
 * Chosen once in Settings -> Branding and used everywhere an amount appears:
 * the website pricing, the member Premium page and the admin payment logs.
 * Stored as an ISO code rather than a symbol, so formatting, payment gateways
 * and logs all agree on what the number means.
 */
final class Currency
{
    /** @var array<string, array{symbol: string, name: string}> */
    public const SUPPORTED = [
        'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
        'INR' => ['symbol' => '₹', 'name' => 'Indian Rupee'],
        'EUR' => ['symbol' => '€', 'name' => 'Euro'],
        'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
    ];

    public const DEFAULT = 'USD';

    public static function code(): string
    {
        $code = strtoupper((string) veyra_setting('billing.currency', self::DEFAULT));

        return array_key_exists($code, self::SUPPORTED) ? $code : self::DEFAULT;
    }

    public static function symbol(?string $code = null): string
    {
        $code = strtoupper($code ?? self::code());

        return self::SUPPORTED[$code]['symbol'] ?? $code.' ';
    }

    /**
     * An amount with its symbol: $12.99, ₹1,299.00, €24.99.
     *
     * Rupees use Indian digit grouping (1,00,000), which is what an Indian
     * reader expects; everything else uses the familiar thousands grouping.
     */
    public static function format(float|int|string|null $amount, ?string $code = null): string
    {
        $code = strtoupper($code ?? self::code());
        $amount = (float) ($amount ?? 0);

        $number = $code === 'INR'
            ? self::indianGrouping($amount)
            : number_format($amount, 2, '.', ',');

        return self::symbol($code).$number;
    }

    /** @return array<string, string> code => "US Dollar ($)" */
    public static function options(): array
    {
        return collect(self::SUPPORTED)
            ->mapWithKeys(fn (array $c, string $code): array => [$code => "{$c['name']} ({$c['symbol']})"])
            ->all();
    }

    private static function indianGrouping(float $amount): string
    {
        $negative = $amount < 0;
        [$whole, $fraction] = explode('.', number_format(abs($amount), 2, '.', ''));

        $lastThree = substr($whole, -3);
        $rest = substr($whole, 0, -3);

        if ($rest !== '') {
            $lastThree = ','.$lastThree;
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        }

        return ($negative ? '-' : '').$rest.$lastThree.'.'.$fraction;
    }
}
