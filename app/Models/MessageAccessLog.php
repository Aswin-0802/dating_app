<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A record of staff reading member message content.
 *
 * Immutable for the same reason the moderation log is: this table is the
 * evidence that the privacy gate is real rather than decorative, and evidence
 * that can be edited afterwards proves nothing.
 */
class MessageAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Message access logs are immutable.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Message access logs cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function reportCase(): BelongsTo
    {
        return $this->belongsTo(ReportCase::class);
    }

    public function reasonLabel(): string
    {
        return config('platform.privacy.reveal_reasons.'.$this->reason_code, $this->reason_code);
    }
}
