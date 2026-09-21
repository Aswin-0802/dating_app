<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One place a member can be reached by push. */
class PushToken extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function scopeForPlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    /**
     * Register a token, or move it to this member.
     *
     * Tokens travel: a shared tablet, or somebody signing out and a colleague
     * signing in, hands the same token to a different account. The token is
     * unique, so the newest owner wins — otherwise one person would receive
     * another's notifications.
     */
    public static function register(AppUser $member, string $token, string $platform, ?string $label = null, ?int $deviceId = null): self
    {
        return self::query()->updateOrCreate(
            ['token' => $token],
            [
                'app_user_id' => $member->id,
                'platform' => $platform,
                'label' => $label,
                'device_id' => $deviceId,
                'last_used_at' => now(),
                'failure_count' => 0,
                'last_failed_at' => null,
            ],
        );
    }
}
