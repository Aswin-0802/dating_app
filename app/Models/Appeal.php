<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppealStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appeal extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AppealStatus::class,
            'decided_at' => 'datetime',
            'sla_due_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    public function ban(): BelongsTo
    {
        return $this->belongsTo(Ban::class);
    }

    public function moderationAction(): BelongsTo
    {
        return $this->belongsTo(ModerationAction::class);
    }

    public function originalDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_decider_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['new', 'assigned', 'in_review']);
    }

    public function scopeBreachingSla(Builder $query): Builder
    {
        return $query->open()->where('sla_due_at', '<', now());
    }

    /**
     * An appeal may never be reviewed by whoever made the original decision.
     *
     * Checked here, in the assign action and in the policy — three places,
     * because the consequence of getting it wrong is an appeals process that
     * looks legitimate and rubber-stamps every decision it reviews.
     */
    public function canBeDecidedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->original_decider_id !== null && $user->id === $this->original_decider_id) {
            return false;
        }

        return $user->can('decide_appeals');
    }
}
