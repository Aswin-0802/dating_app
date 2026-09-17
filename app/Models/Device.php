<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    /**
     * Other accounts seen on this same device.
     *
     * Shared hardware is the strongest single signal for ban evasion and
     * duplicate signups, so it is a first-class lookup rather than an ad-hoc query.
     */
    public function siblingAccounts(): Builder
    {
        return self::query()
            ->where('fingerprint_hash', $this->fingerprint_hash)
            ->where('app_user_id', '!=', $this->app_user_id)
            ->with('appUser');
    }
}
