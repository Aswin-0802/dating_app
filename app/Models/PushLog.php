<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PushCampaign::class, 'push_campaign_id');
    }

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function statusClasses(): string
    {
        return match ($this->status) {
            'opened' => 'bg-success-subtle text-success-subtle-foreground',
            'delivered' => 'bg-info-subtle text-info-subtle-foreground',
            'sent' => 'bg-muted text-muted-foreground',
            'queued' => 'bg-muted text-muted-foreground',
            'failed' => 'bg-destructive-subtle text-destructive-subtle-foreground',
            default => 'bg-muted text-muted-foreground',
        };
    }
}
