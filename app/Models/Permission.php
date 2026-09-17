<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Extends spatie's model with the grouping metadata the role matrix needs.
 */
class Permission extends SpatiePermission
{
    protected function casts(): array
    {
        return ['is_sensitive' => 'boolean'];
    }

    public function scopeSensitive(Builder $query): Builder
    {
        return $query->where('is_sensitive', true);
    }

    public function getDisplayLabelAttribute(): string
    {
        return $this->label ?? str($this->name)->headline()->toString();
    }

    /**
     * Permissions grouped and ordered for the matrix UI.
     *
     * @return Collection<string, Collection<int, self>>
     */
    public static function grouped(): Collection
    {
        return static::query()
            ->orderBy('group_name')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy('group_name');
    }
}
