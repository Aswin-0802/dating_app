<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A message between two members.
 *
 * `body` is in $hidden, which is the structural half of the privacy design: a
 * message can be loaded, counted, filtered and flagged without its content ever
 * reaching a view or a JSON response by accident. The only way to read it in the
 * admin is through MessageRevealService, which checks the permission and writes
 * an access log.
 */
class Message extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['body'];

    protected function casts(): array
    {
        return [
            'contains_link' => 'boolean',
            'contains_contact_info' => 'boolean',
            'is_flagged' => 'boolean',
            'flag_labels' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'sender_app_user_id');
    }

    public function scopeFlagged(Builder $query): Builder
    {
        return $query->where('is_flagged', true);
    }

    /**
     * What the admin shows before a reveal: shape and signals, never content.
     *
     * Moderators can triage a surprising amount from length, direction and
     * flags alone, which keeps the number of justified reveals down.
     */
    public function redactedPreview(): string
    {
        if ($this->moderation_status === 'removed') {
            return '[removed by moderation]';
        }

        return match ($this->type) {
            'image' => '[image]',
            'gif' => '[gif]',
            'voice' => '[voice note]',
            'system' => '[system message]',
            default => '[text · '.mb_strlen((string) $this->getRawOriginal('body')).' characters]',
        };
    }
}
