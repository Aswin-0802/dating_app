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
