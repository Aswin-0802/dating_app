<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A mutual like.
 *
 * Named MatchRecord because `Match` is a reserved word in PHP 8. The table is
 * `matches`, which MySQL accepts.
 */
class MatchRecord extends Model
{
    use HasUuids;

    protected $table = 'matches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'matched_at' => 'datetime',
            'first_message_at' => 'datetime',
            'first_reply_at' => 'datetime',
            'last_message_at' => 'datetime',
            'same_city' => 'boolean',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        /*
         * Canonical ordering. Without it the unique index on the pair does not
         * work — (A,B) and (B,A) would be two different rows and a pair could
         * match twice.
         */
        static::saving(function (self $match): void {
            if ($match->app_user_one_id > $match->app_user_two_id) {
                [$match->app_user_one_id, $match->app_user_two_id] =
                    [$match->app_user_two_id, $match->app_user_one_id];
            }
        });
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'app_user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'app_user_two_id');
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class, 'match_id');
    }

    public function otherParty(AppUser|int $appUser): ?AppUser
    {
        $id = $appUser instanceof AppUser ? $appUser->id : $appUser;

        return $id === $this->app_user_one_id ? $this->userTwo : $this->userOne;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Matches where nobody ever said anything — the core engagement problem. */
    public function scopeSilent(Builder $query): Builder
    {
        return $query->whereNull('first_message_at');
    }

    public function scopeInvolving(Builder $query, AppUser|int $appUser): Builder
    {
        $id = $appUser instanceof AppUser ? $appUser->id : $appUser;

        return $query->where(fn (Builder $q) => $q
            ->where('app_user_one_id', $id)
            ->orWhere('app_user_two_id', $id));
    }
}
