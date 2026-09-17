<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasBadge;

/**
 * The graduated enforcement ladder.
 *
 * Ordered deliberately: `rung()` drives both the UI ordering and the check that
 * stops a moderator jumping straight to a permanent ban on a first offence
 * without an explicit override.
 */
enum LadderStep: string
{
    use HasBadge;

    case Note = 'note';
    case Warn = 'warn';
    case FeatureLimit = 'feature_limit';
    case ShadowBan = 'shadow_ban';
    case Suspend = 'suspend';
    case PermanentBan = 'permanent_ban';
    case DeviceBan = 'device_ban';
    case Escalate = 'escalate';
    case Lift = 'lift';

    public function label(): string
    {
        return match ($this) {
            self::Note => 'Add note',
            self::Warn => 'Warn',
            self::FeatureLimit => 'Limit features',
            self::ShadowBan => 'Shadow ban',
            self::Suspend => 'Suspend',
            self::PermanentBan => 'Permanent ban',
            self::DeviceBan => 'Ban device',
            self::Escalate => 'Escalate',
            self::Lift => 'Lift enforcement',
        };
    }

    public function rung(): int
    {
        return match ($this) {
            self::Note => 0,
            self::Warn => 1,
            self::FeatureLimit => 2,
            self::ShadowBan => 3,
            self::Suspend => 4,
            self::PermanentBan => 5,
            self::DeviceBan => 6,
            self::Escalate => 7,
            self::Lift => -1,
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Note => 'bg-muted text-muted-foreground',
            self::Warn => 'bg-warning-subtle text-warning-subtle-foreground',
            self::FeatureLimit => 'bg-risk-high-subtle text-risk-high-subtle-foreground',
            self::ShadowBan => 'bg-risk-high-subtle text-risk-high-subtle-foreground',
            self::Suspend => 'bg-destructive-subtle text-destructive-subtle-foreground',
            self::PermanentBan, self::DeviceBan => 'bg-destructive text-white',
            self::Escalate => 'bg-accent text-accent-foreground',
            self::Lift => 'bg-success-subtle text-success-subtle-foreground',
        };
    }

    public function buttonClasses(): string
    {
        return match ($this) {
            self::Note => 'bg-muted text-foreground hover:bg-muted/80',
            self::Warn => 'bg-warning text-warning-foreground hover:bg-warning/90',
            self::FeatureLimit, self::ShadowBan => 'bg-risk-high text-white hover:bg-risk-high/90',
            self::Suspend, self::PermanentBan, self::DeviceBan => 'bg-destructive text-white hover:bg-destructive/90',
            self::Escalate => 'bg-accent text-accent-foreground hover:bg-accent/90',
            self::Lift => 'bg-success text-success-foreground hover:bg-success/90',
        };
    }

    /** Single-key shortcut shown on the decision bar. */
    public function shortcut(): ?string
    {
        return match ($this) {
            self::Warn => 'W',
            self::FeatureLimit => 'L',
            self::ShadowBan => 'H',
            self::Suspend => 'S',
            self::PermanentBan => 'B',
            self::Escalate => 'E',
            default => null,
        };
    }

    /** Steps that create a `bans` row rather than only a moderation action. */
    public function createsBan(): bool
    {
        return in_array(
            $this,
            [self::FeatureLimit, self::ShadowBan, self::Suspend, self::PermanentBan, self::DeviceBan],
            true,
        );
    }

    /** Steps that require a duration to be collected in the confirm modal. */
    public function requiresDuration(): bool
    {
        return in_array($this, [self::FeatureLimit, self::ShadowBan, self::Suspend], true);
    }

    /**
     * Shadow bans must carry a mandatory review date. A silent, permanent,
     * never-revisited throttle is the failure mode this whole module exists to
     * design out.
     */
    public function requiresReviewDate(): bool
    {
        return $this === self::ShadowBan;
    }

    public function isDestructive(): bool
    {
        return $this->rung() >= self::Suspend->rung() && $this !== self::Lift;
    }

    /** The member can appeal anything from a feature limit upward. */
    public function isAppealable(): bool
    {
        return $this->rung() >= self::FeatureLimit->rung() && $this !== self::Escalate;
    }

    /** @return array<int, self> Steps offered on a case decision bar, in order. */
    public static function decisionBar(): array
    {
        return [
            self::Warn,
            self::FeatureLimit,
            self::ShadowBan,
            self::Suspend,
            self::PermanentBan,
            self::Escalate,
        ];
    }
}
