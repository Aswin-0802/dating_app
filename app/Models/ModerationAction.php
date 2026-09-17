<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LadderStep;
use App\Enums\ReasonCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An enforcement decision. Append-only.
 *
 * Updates and deletes throw rather than failing quietly: this table is the
 * evidence base for appeals and for transparency reporting, and a record that
 * can be rewritten after the fact is not evidence.
 */
class ModerationAction extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ladder_step' => LadderStep::class,
            'reason_code' => ReasonCode::class,
            'notified_user' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(
                'Moderation actions are append-only. Record a reversal instead of editing.',
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException('Moderation actions cannot be deleted.');
        });
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'subject_app_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function reportCase(): BelongsTo
    {
        return $this->belongsTo(ReportCase::class);
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_action_id');
    }

    public function scopeByHumans(Builder $query): Builder
    {
        return $query->where('actor_type', 'human');
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_action_id !== null;
    }

    public function actorLabel(): string
    {
        return $this->actor_type === 'automation'
            ? 'Automated rule'
            : ($this->actor?->name ?? 'Unknown');
    }
}
