<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Models\PaymentGateway;

/**
 * Which driver speaks for which store — and, in tests, a way to put a fake
 * in its place so the billing rules can be exercised without Apple or Google.
 */
final class StoreDrivers
{
    public const STORES = ['apple', 'google'];

    /** @var array<string, StoreDriver> */
    private static array $swapped = [];

    public static function isStore(?string $slug): bool
    {
        return in_array($slug, self::STORES, true);
    }

    /** The driver for a store that is switched on, or null when it is not. */
    public function for(string $store): ?StoreDriver
    {
        if (isset(self::$swapped[$store])) {
            return self::$swapped[$store];
        }

        $gateway = PaymentGateway::query()->where('slug', $store)->where('kind', 'store')->first();

        if ($gateway === null || ! $gateway->is_active) {
            return null;
        }

        return match ($store) {
            'apple' => new AppleStoreDriver($gateway),
            'google' => new GoogleStoreDriver($gateway),
            default => null,
        };
    }

    /** Tests only. */
    public static function swap(string $store, ?StoreDriver $driver): void
    {
        if ($driver === null) {
            unset(self::$swapped[$store]);
        } else {
            self::$swapped[$store] = $driver;
        }
    }

    public static function reset(): void
    {
        self::$swapped = [];
    }
}
