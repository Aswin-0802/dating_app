<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RiskBand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskScore extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'band' => RiskBand::class,
            'is_current' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    public function factors(): HasMany
    {
        return $this->hasMany(RiskFactor::class);
    }
}
