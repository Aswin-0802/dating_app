<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;

/**
 * A staff member: admin, moderator, analyst or support.
 *
 * Dating-app members are App\Models\AppUser and authenticate on a separate
 * guard. Nothing here should ever describe a member.
 */
class User extends Authenticatable
{
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    // ---- relationships ----

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** Enforcement decisions this staff member issued. */
    public function moderationActions(): HasMany
    {
        return $this->hasMany(ModerationAction::class, 'actor_id');
    }

    public function messageAccessLogs(): HasMany
    {
        return $this->hasMany(MessageAccessLog::class);
    }

    // ---- scopes ----

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Staff who may decide an appeal. Used to populate the assignment select,
     * which must never offer the moderator who made the original decision.
     */
    public function scopeCanDecideAppeals(Builder $query): Builder
    {
        return $query->active()->whereHas(
            'permissions',
            fn (Builder $inner) => $inner->where('name', 'decide_appeals'),
        )->orWhereHas(
            'roles.permissions',
            fn (Builder $inner) => $inner->where('name', 'decide_appeals'),
        );
    }

    // ---- accessors ----

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function getRoleNameAttribute(): string
    {
        return $this->getRoleNames()->first() ?? 'No role';
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
