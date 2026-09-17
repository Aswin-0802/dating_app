<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CaseStatus;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A case aggregates every report against one member.
 *
 * Reports are the raw signal; the case is the unit of work. Acting once
 * resolves every underlying report, which is what stops three moderators
 * independently reviewing the same account on the same afternoon.
 */
class ReportCase extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CaseStatus::class,
            'severity' => Severity::class,
            'claimed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'sla_due_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'case_number';
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'subject_app_user_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class)->latest();
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ModerationAction::class)->latest();
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(Appeal::class, 'moderation_action_id', 'id');
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['new', 'claimed', 'in_review']);
    }

    public function scopeBreachingSla(Builder $query): Builder
    {
        return $query->open()->where('sla_due_at', '<', now());
    }

    public function isBreachingSla(): bool
    {
        return $this->status->isOpen() && $this->sla_due_at?->isPast();
    }

    /** Whether another moderator currently holds this case. */
    public function isClaimedByAnotherUser(?int $userId): bool
    {
        return $this->claimed_by !== null && $this->claimed_by !== $userId;
    }
}
