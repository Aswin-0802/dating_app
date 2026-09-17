<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One contributing factor behind a risk score.
 *
 * The breakdown the UI shows is literally these rows — never recomputed at
 * render time. That is what guarantees the factors always sum to the number on
 * the badge, including for a score computed months ago under weights that have
 * since been retuned.
 */
class RiskFactor extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }

    public function riskScore(): BelongsTo
    {
        return $this->belongsTo(RiskScore::class);
    }

    public function isMitigating(): bool
    {
        return $this->points < 0;
    }
}
