<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One spell on a paid plan. See the subscriptions migration for why. */
class Subscription extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'amount' => 'decimal:2',
            'reminders_sent' => 'array',
        ];
    }

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Active subscriptions whose end date has passed. */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query->active()->whereNotNull('ends_at')->where('ends_at', '<=', now());
    }

    public function isOpenEnded(): bool
    {
        return $this->ends_at === null;
    }

    public function daysLeft(): ?int
    {
        return $this->ends_at === null ? null : (int) ceil(now()->floatDiffInDays($this->ends_at, false));
    }

    public function hasSentReminder(string $key): bool
    {
        return in_array($key, $this->reminders_sent ?? [], true);
    }

    public function markReminderSent(string $key): void
    {
        $this->forceFill([
            'reminders_sent' => array_values(array_unique([...($this->reminders_sent ?? []), $key])),
        ])->save();
    }
}
