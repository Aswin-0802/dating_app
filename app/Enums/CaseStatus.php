<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasBadge;

enum CaseStatus: string
{
    use HasBadge;

    case New = 'new';
    case Claimed = 'claimed';
    case InReview = 'in_review';
    case Actioned = 'actioned';
    case Appealed = 'appealed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Claimed => 'Claimed',
            self::InReview => 'In review',
            self::Actioned => 'Actioned',
            self::Appealed => 'Appealed',
            self::Closed => 'Closed',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            // New is solid: it is the only status that means "nobody has looked".
            self::New => 'bg-info text-white',
            self::Claimed => 'bg-secondary text-secondary-foreground',
            self::InReview => 'bg-info-subtle text-info-subtle-foreground',
            // Outline variants sit on the page background, so their text uses
            // the subtle-foreground too — it is the token that is dark in light
            // mode and light in dark mode.
            self::Actioned => 'bg-transparent text-success-subtle-foreground ring-1 ring-inset ring-success/40',
            self::Appealed => 'bg-transparent text-warning-subtle-foreground ring-1 ring-inset ring-warning/50',
            self::Closed => 'bg-muted text-muted-foreground',
        };
    }

    public function isUrgent(): bool
    {
        return $this === self::New;
    }

    /** Statuses still counted against the queue and the SLA clock. */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Claimed, self::InReview], true);
    }

    /** @return array<int, self> */
    public static function open(): array
    {
        return [self::New, self::Claimed, self::InReview];
    }
}
