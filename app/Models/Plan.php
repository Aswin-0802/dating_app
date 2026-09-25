<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Masters;
use Illuminate\Database\Eloquent\Model;

/**
 * A subscription plan, managed in Masters -> Subscription plans.
 *
 * Features are fixed keys with real behaviour behind them; everything else —
 * name, prices, wording, colour, order — is the operator's to change.
 */
class Plan extends Model
{
    /** Feature key => what it does, as members and staff see it. */
    public const FEATURES = [
        'unlimited_likes' => 'Unlimited likes',
        'see_likers' => 'See who has already liked you',
        'profile_badge' => 'Plan badge on your profile',
        'priority_support' => 'Priority support',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'perks' => 'array',
            'monthly_price' => 'decimal:2',
            'yearly_price' => 'decimal:2',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Masters::flush());
        static::deleted(fn () => Masters::flush());
    }

    public function hasFeature(string $key): bool
    {
        return in_array($key, $this->features ?? [], true);
    }

    /**
     * How "good" a plan is when a member holds two at once: the pricier
     * plan wins the mirror. Never shorten, never downgrade.
     */
    public function rank(): float
    {
        return (float) $this->monthly_price;
    }

    /** The store column for a store and period, e.g. apple_product_id_yearly. */
    public static function storeColumn(string $store, string $period): string
    {
        return "{$store}_product_id_{$period}";
    }

    /**
     * The plan and period a store product identifier maps to, or null when
     * nothing is mapped — the API answers `product_unknown` for that.
     *
     * @return array{plan: Plan, period: string}|null
     */
    public static function forStoreProduct(string $store, string $productId): ?array
    {
        if ($productId === '' || ! in_array($store, ['apple', 'google'], true)) {
            return null;
        }

        foreach (['monthly', 'yearly'] as $period) {
            $plan = static::query()->where(self::storeColumn($store, $period), $productId)->first();

            if ($plan !== null) {
                return ['plan' => $plan, 'period' => $period];
            }
        }

        return null;
    }

    /**
     * Store product identifiers for the mobile app, by platform. A period
     * with no product is omitted.
     *
     * @return array{ios: array<string, string>, android: array<string, string>}
     */
    public function storeProducts(): array
    {
        return [
            'ios' => array_filter(['monthly' => $this->apple_product_id_monthly, 'yearly' => $this->apple_product_id_yearly]),
            'android' => array_filter(['monthly' => $this->google_product_id_monthly, 'yearly' => $this->google_product_id_yearly]),
        ];
    }

    /** @return array<int, string> the feature and perk lines for a pricing card */
    public function benefitLines(): array
    {
        return [
            ...($this->perks ?? []),
            ...collect($this->features ?? [])->map(fn (string $f): ?string => self::FEATURES[$f] ?? null)->filter()->values()->all(),
        ];
    }

    /** Saving on the yearly price versus twelve monthly payments, as a whole percent. */
    public function yearlySavingPercent(): ?int
    {
        if ($this->yearly_price === null || (float) $this->monthly_price <= 0) {
            return null;
        }

        $saving = 1 - ((float) $this->yearly_price / ((float) $this->monthly_price * 12));

        return $saving > 0.01 ? (int) round($saving * 100) : null;
    }

    public function membersCount(): int
    {
        return AppUser::query()->where('premium_tier', $this->slug)->where('is_premium', true)->count();
    }
}
