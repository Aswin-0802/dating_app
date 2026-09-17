<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasBadge;

enum Severity: string
{
    use HasBadge;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Low => 'bg-muted text-muted-foreground',
            self::Medium => 'bg-warning-subtle text-warning-subtle-foreground',
            self::High => 'bg-risk-high-subtle text-risk-high-subtle-foreground',
            self::Critical => 'bg-destructive text-white',
        };
    }

    public function isUrgent(): bool
    {
        return $this === self::Critical;
    }

    /**
     * Hours a case at this severity may sit before it breaches SLA.
     *
     * Triage is by harm potential, never pure FIFO — a scam report must not
     * queue behind a taste complaint filed an hour earlier.
     */
    public function slaHours(): int
    {
        return match ($this) {
            self::Low => 72,
            self::Medium => 24,
            self::High => 8,
            self::Critical => 1,
        };
    }

    /** Sort weight, descending, for "highest severity first" queue ordering. */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }
}
