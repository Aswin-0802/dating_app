<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasBadge;

enum AppealStatus: string
{
    use HasBadge;

    case New = 'new';
    case Assigned = 'assigned';
    case InReview = 'in_review';
    case Upheld = 'upheld';
    case Overturned = 'overturned';
    case PartiallyOverturned = 'partially_overturned';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Assigned => 'Assigned',
            self::InReview => 'In review',
            self::Upheld => 'Upheld',
            self::Overturned => 'Overturned',
            self::PartiallyOverturned => 'Partially overturned',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::New => 'bg-info text-white',
            self::Assigned => 'bg-secondary text-secondary-foreground',
            self::InReview => 'bg-info-subtle text-info-subtle-foreground',
            self::Upheld => 'bg-muted text-muted-foreground',
            self::Overturned => 'bg-success-subtle text-success-subtle-foreground',
            self::PartiallyOverturned => 'bg-warning-subtle text-warning-subtle-foreground',
            self::Withdrawn => 'bg-muted text-muted-foreground',
        };
    }

    public function isUrgent(): bool
    {
        return $this === self::New;
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Assigned, self::InReview], true);
    }

    /** Outcomes that reverse some or all of the original enforcement. */
    public function reversesEnforcement(): bool
    {
        return in_array($this, [self::Overturned, self::PartiallyOverturned], true);
    }
}
