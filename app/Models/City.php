<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_focus' => 'boolean',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function appUsers(): HasMany
    {
        return $this->hasMany(AppUser::class);
    }

    public function scopeFocus(Builder $query): Builder
    {
        return $query->where('is_focus', true);
    }

    public function getLabelAttribute(): string
    {
        return $this->name.', '.($this->country?->iso2 ?? '—');
    }
}
