<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EmailLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', ['failed', 'bounced']);
    }

    public function statusClasses(): string
    {
        return match ($this->status) {
            'delivered' => 'bg-success-subtle text-success-subtle-foreground',
            'sent' => 'bg-info-subtle text-info-subtle-foreground',
            'queued' => 'bg-muted text-muted-foreground',
            'bounced', 'failed' => 'bg-destructive-subtle text-destructive-subtle-foreground',
            default => 'bg-muted text-muted-foreground',
        };
    }
}
