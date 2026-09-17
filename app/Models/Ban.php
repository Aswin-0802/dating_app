<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BanType;
use App\Enums\ReasonCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ban extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => BanType::class,
            'reason_code' => ReasonCode::class,
            'limited_features' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'review_due_at' => 'datetime',
            'lifted_at' => 'datetime',
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

    public function moderationAction(): BelongsTo
    {
        return $this->belongsTo(ModerationAction::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function liftedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lifted_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Shadow bans whose review date has passed.
     *
     * This queue is the safeguard that stops a shadow ban becoming a permanent,
     * invisible, never-revisited punishment.
     */
    public function scopeReviewDue(Builder $query): Builder
    {
        return $query->active()
            ->where('type', BanType::ShadowBan->value)
            ->whereNotNull('review_due_at')
            ->where('review_due_at', '<=', now());
    }

    public function scopeExpiring(Builder $query): Builder
    {
        return $query->active()->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isReviewOverdue(): bool
    {
        return $this->isActive()
            && $this->review_due_at !== null
            && $this->review_due_at->isPast();
    }
}
