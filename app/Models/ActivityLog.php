<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Immutable record of a staff action.
 *
 * An audit trail that can be edited is not an audit trail, so updates and
 * deletes throw rather than failing silently.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'is_sensitive' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Activity log entries are immutable.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Activity log entries cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    // ---- scopes ----

    public function scopeModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    public function scopeSensitive(Builder $query): Builder
    {
        return $query->where('is_sensitive', true);
    }

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    /**
     * Fields that actually changed, for the diff viewer.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function getChangesDiffAttribute(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];
        $diff = [];

        foreach (array_keys($old + $new) as $field) {
            $before = $old[$field] ?? null;
            $after = $new[$field] ?? null;

            if ($before !== $after) {
                $diff[$field] = ['old' => $before, 'new' => $after];
            }
        }

        return $diff;
    }
}
