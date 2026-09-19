<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentLog extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'response' => 'array',
            'disputed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', 'succeeded');
    }

    /**
     * Disputes are a fraud signal as much as a finance one — a member who
     * disputes several payments is usually worth a look, not just a refund.
     */
    public function scopeDisputed(Builder $query): Builder
    {
        return $query->where('status', 'disputed');
    }

    public function statusClasses(): string
    {
        return match ($this->status) {
            'succeeded' => 'bg-success-subtle text-success-subtle-foreground',
            'pending' => 'bg-muted text-muted-foreground',
            'refunded' => 'bg-warning-subtle text-warning-subtle-foreground',
            'disputed' => 'bg-destructive text-white',
            'failed' => 'bg-destructive-subtle text-destructive-subtle-foreground',
            default => 'bg-muted text-muted-foreground',
        };
    }

    public function formattedAmount(): string
    {
        // Each payment keeps the currency it was charged in, whatever the
        // currency setting is today.
        return Currency::format($this->amount, $this->currency);
    }
}
