<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasBadge;

enum BanType: string
{
    use HasBadge;

    case FeatureLimit = 'feature_limit';
    case ShadowBan = 'shadow_ban';
    case Suspension = 'suspension';
    case PermanentBan = 'permanent_ban';
    case DeviceBan = 'device_ban';

    public function label(): string
    {
        return match ($this) {
            self::FeatureLimit => 'Feature limit',
            self::ShadowBan => 'Shadow ban',
            self::Suspension => 'Suspension',
            self::PermanentBan => 'Permanent ban',
            self::DeviceBan => 'Device ban',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::FeatureLimit => 'bg-warning-subtle text-warning-subtle-foreground',
            self::ShadowBan => 'bg-risk-high-subtle text-risk-high-subtle-foreground',
            self::Suspension => 'bg-destructive-subtle text-destructive-subtle-foreground',
            self::PermanentBan, self::DeviceBan => 'bg-destructive text-white',
        };
    }

    public function isUrgent(): bool
    {
        return in_array($this, [self::PermanentBan, self::DeviceBan], true);
    }

    public function accountStatus(): AccountStatus
    {
        return match ($this) {
            self::FeatureLimit => AccountStatus::Limited,
            self::ShadowBan => AccountStatus::ShadowBanned,
            self::Suspension => AccountStatus::Suspended,
            self::PermanentBan, self::DeviceBan => AccountStatus::Banned,
        };
    }

    public function isTemporary(): bool
    {
        return in_array($this, [self::FeatureLimit, self::ShadowBan, self::Suspension], true);
    }

    public function fromLadderStep(LadderStep $step): ?self
    {
        return self::tryFrom($step->value);
    }

    /**
     * Whether staff must set a review date. Only shadow bans qualify: they are
     * invisible to the member, so nothing but a calendar entry will ever prompt
     * someone to revisit them.
     */
    public function requiresReviewDate(): bool
    {
        return $this === self::ShadowBan;
    }
}
