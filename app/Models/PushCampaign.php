<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PushCampaign extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'audience_filters' => 'array',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'approved_at' => 'datetime',
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

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PushLog::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Sending to the whole member base is not a one-person decision, and a
     * campaign cannot go out until somebody other than its author has read it.
     */
    public function canBeSent(): bool
    {
        return $this->approved_at !== null
            && in_array($this->status, ['draft', 'scheduled'], true);
    }

    public function needsApproval(): bool
    {
        return $this->approved_at === null && $this->status !== 'cancelled';
    }

    public function deliveryRate(): ?float
    {
        return $this->sent_count > 0
            ? round($this->delivered_count / $this->sent_count * 100, 1)
            : null;
    }

    /**
     * Open rate against DELIVERED, not against sent.
     *
     * Measuring against sent quietly credits a campaign for notifications that
     * never arrived, which makes a delivery problem look like an engagement one.
     */
    public function openRate(): ?float
    {
        return $this->delivered_count > 0
            ? round($this->opened_count / $this->delivered_count * 100, 1)
            : null;
    }

    public function statusClasses(): string
    {
        return match ($this->status) {
            'draft' => 'bg-muted text-muted-foreground',
            'scheduled' => 'bg-info-subtle text-info-subtle-foreground',
            'sending' => 'bg-warning-subtle text-warning-subtle-foreground',
            'sent' => 'bg-success-subtle text-success-subtle-foreground',
            'cancelled' => 'bg-destructive-subtle text-destructive-subtle-foreground',
            default => 'bg-muted text-muted-foreground',
        };
    }
}
