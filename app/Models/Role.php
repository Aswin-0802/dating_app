<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public const SUPER_ADMIN = 'Super Admin';

    public const ADMIN = 'Admin';

    public const TS_LEAD = 'T&S Lead';

    public const SENIOR_MODERATOR = 'Senior Moderator';

    public const MODERATOR = 'Moderator';

    public const SUPPORT = 'Support';

    public const ANALYST = 'Analyst';

    /**
     * Roles that must always exist and cannot be deleted from the UI. Deleting
     * Super Admin would lock the instance out permanently.
     */
    public function isProtected(): bool
    {
        return in_array($this->name, [self::SUPER_ADMIN, self::ADMIN], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === self::SUPER_ADMIN;
    }

    /** Badge colour per role, so staff lists are scannable. */
    public function badgeClasses(): string
    {
        return match ($this->name) {
            self::SUPER_ADMIN => 'bg-destructive-subtle text-destructive-subtle-foreground',
            self::ADMIN => 'bg-primary-subtle text-primary-subtle-foreground',
            self::TS_LEAD => 'bg-accent-subtle text-accent-subtle-foreground',
            self::SENIOR_MODERATOR => 'bg-info-subtle text-info-subtle-foreground',
            self::MODERATOR => 'bg-success-subtle text-success-subtle-foreground',
            self::SUPPORT => 'bg-warning-subtle text-warning-subtle-foreground',
            default => 'bg-muted text-muted-foreground',
        };
    }
}
