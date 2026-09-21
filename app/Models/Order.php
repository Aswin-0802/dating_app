<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attempt to pay. See the orders migration. */
class Order extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'gateway_payload' => 'array',
            'paid_at' => 'datetime',
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

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** The amount as people read it: 4999 paise is ₹49.99. */
    public function amount(): float
    {
        return $this->amount_minor / (Currency::isZeroDecimal($this->currency) ? 1 : 100);
    }

    public function formattedAmount(): string
    {
        return Currency::format($this->amount(), $this->currency);
    }

    public function statusClasses(): string
    {
        return match ($this->status) {
            'paid' => 'bg-success-subtle text-success-subtle-foreground',
            'pending' => 'bg-warning-subtle text-warning-subtle-foreground',
            'failed' => 'bg-destructive-subtle text-destructive-subtle-foreground',
            default => 'bg-muted text-muted-foreground',
        };
    }
}
