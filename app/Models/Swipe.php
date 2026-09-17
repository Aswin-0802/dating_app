<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Swipe extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_match' => 'boolean', 'created_at' => 'datetime'];
    }

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'target_app_user_id');
    }

    public function scopeLikes(Builder $query): Builder
    {
        return $query->whereIn('action', ['like', 'superlike']);
    }
}
