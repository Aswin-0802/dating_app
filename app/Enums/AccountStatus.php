<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasBadge;

enum AccountStatus: string
{
    use HasBadge;

    case Active = 'active';
    case Pending = 'pending';
    case Limited = 'limited';
    case ShadowBanned = 'shadow_banned';
    case Suspended = 'suspended';
    case Banned = 'banned';
    case Deactivated = 'deactivated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Pending => 'Pending verification',
            self::Limited => 'Feature limited',
            self::ShadowBanned => 'Shadow banned',
            self::Suspended => 'Suspended',
            self::Banned => 'Banned',
            self::Deactivated => 'Deactivated',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Active => 'bg-success-subtle text-success-subtle-foreground',
            self::Pending => 'bg-warning-subtle text-warning-subtle-foreground',
            self::Limited => 'bg-risk-high-subtle text-risk-high-subtle-foreground',
            self::ShadowBanned => 'bg-risk-high-subtle text-risk-high-subtle-foreground',
            self::Suspended => 'bg-transparent text-destructive-subtle-foreground ring-1 ring-inset ring-destructive/40',
            // Banned is solid — it is terminal and should read as such.
            self::Banned => 'bg-destructive text-white',
            self::Deactivated => 'bg-muted text-muted-foreground',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Active => 'check-circle',
            self::Pending => 'clock',
            self::Limited, self::ShadowBanned => 'eye-off',
            self::Suspended => 'pause-circle',
            self::Banned => 'ban',
            self::Deactivated => 'moon',
        };
    }

    public function isUrgent(): bool
    {
        return $this === self::Banned;
    }

    /** Statuses that restrict what the member can do in the app. */
    public function isRestricted(): bool
    {
        return in_array(
            $this,
            [self::Limited, self::ShadowBanned, self::Suspended, self::Banned],
            true,
        );
    }

    /**
     * What the API is allowed to tell the member they are.
     *
     * A shadow ban that the user can detect is not a shadow ban, so it maps to
     * `active` on the way out. This is the single place that mapping lives.
     */
    public function publicValue(): string
    {
        return $this === self::ShadowBanned ? self::Active->value : $this->value;
    }
}
