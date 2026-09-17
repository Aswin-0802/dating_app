<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SmsLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
            'cost' => 'float',
        ];
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function statusClasses(): string
    {
        return match ($this->status) {
            'delivered' => 'bg-success-subtle text-success-subtle-foreground',
            'sent' => 'bg-info-subtle text-info-subtle-foreground',
            'queued' => 'bg-muted text-muted-foreground',
            'failed' => 'bg-destructive-subtle text-destructive-subtle-foreground',
            default => 'bg-muted text-muted-foreground',
        };
    }

    /** Numbers are masked in the log: staff need the record, not the number. */
    public function maskedNumber(): string
    {
        $digits = preg_replace('/\D/', '', (string) $this->to) ?? '';

        return strlen($digits) <= 4
            ? $digits
            : str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
