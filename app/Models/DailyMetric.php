<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DailyMetric extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'value' => 'float'];
    }

    public function scopeMetric(Builder $query, string $key): Builder
    {
        return $query->where('metric_key', $key);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function scopeDimension(Builder $query, string $dimension, ?string $value = null): Builder
    {
        $query->where('dimension', $dimension);

        return $value === null ? $query : $query->where('dimension_value', $value);
    }
}
