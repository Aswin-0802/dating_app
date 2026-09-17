<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Photo extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'moderation_labels' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** HasUuids would otherwise try to use `uuid` as the primary key. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ---- scopes ----

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('moderation_status', 'approved');
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->whereIn('moderation_status', ['pending', 'auto_flagged']);
    }

    // ---- accessors ----

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getThumbUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->thumb_path ?? $this->path);
    }

    /**
     * Other accounts using a byte-identical image.
     *
     * A hit here is a stolen-photo ring, not a coincidence — people do not
     * upload the same file to two accounts by accident.
     */
    public function duplicatesByHash(): Builder
    {
        return self::query()
            ->where('phash', $this->phash)
            ->whereNotNull('phash')
            ->where('app_user_id', '!=', $this->app_user_id);
    }

    /** Other accounts showing the same face, even in different photos. */
    public function duplicatesByFace(): Builder
    {
        return self::query()
            ->where('face_signature', $this->face_signature)
            ->whereNotNull('face_signature')
            ->where('app_user_id', '!=', $this->app_user_id);
    }

    /** A score high enough to hide the image behind a hold-to-reveal control. */
    public function isGraphic(): bool
    {
        return (float) ($this->moderation_labels['nudity'] ?? 0) > 0.7;
    }
}
