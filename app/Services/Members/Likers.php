<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Enums\AccountStatus;
use App\Models\AppUser;
use Illuminate\Database\Eloquent\Builder;

/**
 * People who have liked a member and are still waiting for an answer.
 *
 * One query, shared by the website and the mobile API, because every clause
 * in it is a safety rule and the two clients must not drift. It excludes:
 *
 *   - anyone the member has already swiped on, either way
 *   - blocks in both directions
 *   - accounts that are not active (pending, restricted, deactivated)
 *   - likes that have already become a match
 *
 * Getting any of those wrong shows the member somebody they chose not to
 * see, or somebody who chose not to be seen by them.
 */
final class Likers
{
    /** @return Builder<AppUser> */
    public function query(AppUser $me): Builder
    {
        return AppUser::query()
            ->whereIn('id', fn ($q) => $q->select('app_user_id')
                ->from('swipes')
                ->where('target_app_user_id', $me->id)
                ->whereIn('action', ['like', 'superlike'])
                ->where('is_match', false))
            ->whereNotIn('id', fn ($q) => $q->select('target_app_user_id')->from('swipes')->where('app_user_id', $me->id))
            ->whereNotIn('id', fn ($q) => $q->select('blocked_app_user_id')->from('blocks')->where('app_user_id', $me->id))
            ->whereNotIn('id', fn ($q) => $q->select('app_user_id')->from('blocks')->where('blocked_app_user_id', $me->id))
            ->where('account_status', AccountStatus::Active->value);
    }

    /** How many are waiting — what a free member is allowed to know. */
    public function count(AppUser $me): int
    {
        return $this->query($me)->count();
    }
}
