<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasBadge;

enum VerificationStatus: string
{
    use HasBadge;

    case Unverified = 'unverified';
    case Pending = 'pending';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Escalated = 'escalated';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::Pending => 'Pending',
            self::InReview => 'In review',
            self::Approved => 'Verified',
            self::Rejected => 'Rejected',
            self::Escalated => 'Escalated',
            self::Expired => 'Expired',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Unverified => 'bg-muted text-muted-foreground',
            self::Pending => 'bg-warning-subtle text-warning-subtle-foreground',
            self::InReview => 'bg-info-subtle text-info-subtle-foreground',
            self::Approved => 'bg-success-subtle text-success-subtle-foreground',
            self::Rejected => 'bg-destructive-subtle text-destructive-subtle-foreground',
            // Escalated must interrupt — it is the minor-suspected path.
            self::Escalated => 'bg-destructive text-white',
            self::Expired => 'bg-muted text-muted-foreground',
        };
    }

    public function isUrgent(): bool
    {
        return $this === self::Escalated;
    }

    /** Statuses still sitting in a moderator queue. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::InReview, self::Escalated], true);
    }
}
