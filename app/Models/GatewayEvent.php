<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A webhook we have already seen.
 *
 * Seen is not the same as handled: the row is written before the work starts so
 * a retry can be recognised, and only `processed_at` says the work finished.
 * A repeat of a processed event changes nothing; a repeat of an unprocessed one
 * is the gateway giving us a second chance, and is taken.
 */
class GatewayEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    public function markProcessed(): void
    {
        $this->forceFill(['processed_at' => now()])->save();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
