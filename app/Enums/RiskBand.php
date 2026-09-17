<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasBadge;

enum RiskBand: string
{
    use HasBadge;

    case Low = 'low';
    case Elevated = 'elevated';
    case High = 'high';
    case Critical = 'critical';

    /**
     * The single place the 0-100 score is bucketed. The queue sort, the row
     * tinting and the badge all read from here so they can never disagree.
     */
    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 75 => self::Critical,
            $score >= 50 => self::High,
            $score >= 25 => self::Elevated,
            default => self::Low,
        };
    }

    public function range(): string
    {
        return match ($this) {
            self::Low => '0–24',
            self::Elevated => '25–49',
            self::High => '50–74',
            self::Critical => '75–100',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Low => 'bg-risk-low-subtle text-risk-low-subtle-foreground',
            self::Elevated => 'bg-risk-elevated-subtle text-risk-elevated-subtle-foreground',
            self::High => 'bg-risk-high-subtle text-risk-high-subtle-foreground',
            // Critical is solid: it is the one band that must interrupt.
            self::Critical => 'bg-risk-critical text-white',
        };
    }

    /** Left border used to tint a table row without hurting legibility. */
    public function rowClasses(): string
    {
        return match ($this) {
            self::Low => '',
            self::Elevated => 'border-l-2 border-l-risk-elevated',
            self::High => 'border-l-2 border-l-risk-high bg-risk-high-subtle/30',
            self::Critical => 'border-l-2 border-l-risk-critical bg-risk-critical-subtle/40',
        };
    }

    public function dotClasses(): string
    {
        return match ($this) {
            self::Low => 'bg-risk-low',
            self::Elevated => 'bg-risk-elevated',
            self::High => 'bg-risk-high',
            self::Critical => 'bg-risk-critical',
        };
    }

    public function meterClasses(): string
    {
        return match ($this) {
            self::Low => 'bg-risk-low',
            self::Elevated => 'bg-risk-elevated',
            self::High => 'bg-risk-high',
            self::Critical => 'bg-risk-critical',
        };
    }

    public function isUrgent(): bool
    {
        return $this === self::Critical;
    }

    /** Bands that should be pushed to the top of a review queue by default. */
    public function needsAttention(): bool
    {
        return in_array($this, [self::High, self::Critical], true);
    }
}
