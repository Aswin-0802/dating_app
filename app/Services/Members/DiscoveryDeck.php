<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Enums\AccountStatus;
use App\Models\AppUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who a member is shown next.
 *
 * Excludes: themselves, anyone already swiped, anyone blocked in either
 * direction, and any account that is not active. Shadow-banned members are
 * excluded because they are not `active` — that removal from discovery IS the
 * shadow ban, and this is the only place it takes effect. Shared by the website
 * and the mobile API so the rule cannot drift between them.
 */
final class DiscoveryDeck
{
    /** @return Collection<int, AppUser> */
    public function for(AppUser $member, int $limit = 20): Collection
    {
        $local = ! $member->preferences?->global_mode && $member->city_id !== null;

        return $this->query($member, $local)->limit(min(30, max(1, $limit)))->get();
    }

    /**
     * The deck, widened beyond the member's city once the city runs dry.
     *
     * The cold-start problem in a new market: a local pool of a dozen people is
     * exhausted in one sitting, and an empty deck on day one is how a new member
     * decides the app is dead. Every other preference still applies — only the
     * city limit is lifted — and the caller is told, so the member can see why
     * people are further away.
     *
     * Used by the website. The mobile API keeps for(), so existing app clients
     * see no change in behaviour.
     *
     * @return array{0: Collection<int, AppUser>, 1: bool} the people, and whether the city limit was lifted
     */
    public function withFallback(AppUser $member, int $limit = 20): array
    {
        $people = $this->for($member, $limit);
        $local = ! $member->preferences?->global_mode && $member->city_id !== null;

        if ($people->isNotEmpty() || ! $local) {
            return [$people, false];
        }

        return [$this->query($member, false)->limit(min(30, max(1, $limit)))->get(), true];
    }

    private function query(AppUser $member, bool $local): Builder
    {
        $preferences = $member->preferences;

        return AppUser::query()
            ->where('id', '!=', $member->id)
            ->where('account_status', AccountStatus::Active->value)
            ->whereNotIn('id', fn ($q) => $q->select('target_app_user_id')
                ->from('swipes')
                ->where('app_user_id', $member->id))
            ->whereNotIn('id', fn ($q) => $q->select('blocked_app_user_id')
                ->from('blocks')
                ->where('app_user_id', $member->id))
            ->whereNotIn('id', fn ($q) => $q->select('app_user_id')
                ->from('blocks')
                ->where('blocked_app_user_id', $member->id))
            ->when($preferences?->interested_in, fn ($q, $genders) => $q->whereIn('gender', $genders))
            ->when($preferences, fn ($q) => $q->ageBetween($preferences->age_min, $preferences->age_max))
            ->when($preferences?->show_verified_only, fn ($q) => $q->verified())
            ->when($local, fn ($q) => $q->where('city_id', $member->city_id))
            ->with(['photos', 'profile', 'city', 'interests'])
            ->inRandomOrder();
    }
}
