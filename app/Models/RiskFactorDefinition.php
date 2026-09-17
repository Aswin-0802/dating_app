<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A tunable risk weight.
 *
 * These live in the database rather than in config so trust & safety can retune
 * the engine without a deploy — the weights are policy, and policy changes
 * faster than releases.
 */
class RiskFactorDefinition extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isMitigating(): bool
    {
        return $this->points < 0;
    }
}
