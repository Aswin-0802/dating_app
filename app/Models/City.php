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
            'is_active' => 'boolean',
        ];
    }

    /**
     * Cities a member may choose right now: the city is shown, its country is
     * shown, and its state (where it has one) is shown. Listings and
     * App\Rules\SelectableCity both read this, so a hidden place is hidden
     * everywhere or nowhere.
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query
            ->where('cities.is_active', true)
            ->whereHas('country', fn (Builder $c) => $c->where('is_active', true))
            ->where(fn (Builder $q) => $q->whereNull('state_id')->orWhereHas('state', fn (Builder $s) => $s->where('is_active', true)));
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
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
