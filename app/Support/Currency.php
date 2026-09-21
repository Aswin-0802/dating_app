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
        $code = strtoupper((string) platform_setting('billing.currency', self::DEFAULT));

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

    /**
     * Currencies whose smallest unit is the whole unit — 500 yen is sent as
     * 500, not 50000.
     *
     * None of the four currencies offered are in this list today, but the
     * conversion below is the one place a gateway integration can silently
     * charge a member a hundred times too much, so it states the rule rather
     * than assuming it.
     */
    public static function isZeroDecimal(?string $code = null): bool
    {
        return in_array(strtoupper($code ?? self::code()), [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW',
            'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ], true);
    }

    /** An amount as the gateways want it: paise, cents. */
    public static function toMinor(float|int|string|null $amount, ?string $code = null): int
    {
        $amount = (float) ($amount ?? 0);

        return (int) round(self::isZeroDecimal($code) ? $amount : $amount * 100);
    }

    public static function fromMinor(int $minor, ?string $code = null): float
    {
        return self::isZeroDecimal($code) ? (float) $minor : $minor / 100;
    }

    /**
     * The smallest charge the gateways accept, per their published limits.
     * Anything under this is refused before a member is sent to pay.
     */
    public static function minimumCharge(?string $code = null): float
    {
        return match (strtoupper($code ?? self::code())) {
            'GBP' => 0.30,
            default => 0.50,
        };
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
