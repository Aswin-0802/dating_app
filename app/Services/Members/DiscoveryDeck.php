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
 *
 * This is the hottest query in the product, so how it is built matters as much
 * as what it returns. Two things it deliberately does not do:
 *
 *  - It does not compute distance over every candidate. A bounding box on
 *    latitude and longitude throws away nearly everybody using an index, and
 *    only the survivors pay for the haversine.
 *  - It does not use ORDER BY RAND(). That materialises and sorts the whole
 *    candidate set on every swipe — "Using temporary; Using filesort" — which
 *    is survivable at fifty members and not at fifty thousand. The deck starts
 *    at a random point on the primary key and walks it in index order instead,
 *    then shuffles the handful of rows it kept in PHP.
 */
final class DiscoveryDeck
{
    private const EARTH_RADIUS_KM = 6371;

    /** One degree of latitude, anywhere on Earth. Longitude narrows with latitude. */
    private const KM_PER_DEGREE = 111.045;

    /** No geographic limit at all. */
    private const SCOPE_ANYWHERE = 'anywhere';

    /** Within the member's chosen radius. */
    private const SCOPE_RADIUS = 'radius';

    /** The member's city, for accounts with no coordinates yet. */
    private const SCOPE_CITY = 'city';

    /** @return Collection<int, AppUser> */
    public function for(AppUser $member, int $limit = 20): Collection
    {
        return $this->take($member, $this->scopeFor($member), $limit);
    }

    /**
     * The deck, widened beyond the member's area once that area runs dry.
     *
     * The cold-start problem in a new market: a local pool of a dozen people is
     * exhausted in one sitting, and an empty deck on day one is how a new member
     * decides the app is dead. Every other preference still applies — only the
     * geographic limit is lifted — and the caller is told, so the member can see
     * why people are further away.
     *
     * Used by the website. The mobile API keeps for(), so existing app clients
     * see no change in behaviour.
     *
     * @return array{0: Collection<int, AppUser>, 1: bool} the people, and whether the limit was lifted
     */
    public function withFallback(AppUser $member, int $limit = 20): array
    {
        $scope = $this->scopeFor($member);
        $people = $this->take($member, $scope, $limit);

        if ($people->isNotEmpty() || $scope === self::SCOPE_ANYWHERE) {
            return [$people, false];
        }

        // Widening drops the radius as well as the city: a member who set 5 km
        // in a quiet town would otherwise be handed an empty deck for ever.
        return [$this->take($member, self::SCOPE_ANYWHERE, $limit), true];
    }

    /**
     * Which geographic limit applies to this member.
     *
     * A radius beats a city whenever we can compute one: city boundaries are
     * arbitrary, and somebody three streets away on the wrong side of one is
     * exactly who the member wanted to see.
     */
    private function scopeFor(AppUser $member): string
    {
        if ($member->preferences?->global_mode) {
            return self::SCOPE_ANYWHERE;
        }

        if ($this->radiusFor($member) !== null) {
            return self::SCOPE_RADIUS;
        }

        return $member->city_id !== null ? self::SCOPE_CITY : self::SCOPE_ANYWHERE;
    }

    /** The member's radius, or null when it cannot be applied. */
    private function radiusFor(AppUser $member): ?int
    {
        $km = $member->preferences?->max_distance_km;

        if ($km === null || $member->last_latitude === null || $member->last_longitude === null) {
            return null;
        }

        return max(1, (int) $km);
    }

    /**
     * Fetch a deck.
     *
     * @return Collection<int, AppUser>
     */
    private function take(AppUser $member, string $scope, int $limit): Collection
    {
        $limit = min(30, max(1, $limit));
        $ids = $this->pickIds($this->query($member, $scope), $limit);

        if ($ids->isEmpty()) {
            return new Collection;
        }

        // Hydrated in one go, so the eager loads are not paid twice by the
        // wrap-around below.
        return AppUser::query()
            ->whereIn('id', $ids)
            ->with(['photos', 'profile', 'city', 'interests'])
            ->get()
            // The ids came back in primary-key order; within a page that is a
            // visible pattern, and oldest-account-first is not a deck.
            ->shuffle()
            ->values();
    }

    /**
     * Pick ids starting from a random point on the primary key.
     *
     * Walking the index from a random pivot costs a range scan instead of a
     * sort of everything. Running off the end wraps to the beginning, so a
     * high pivot does not return a short deck.
     *
     * @return Collection<int, int>
     */
    private function pickIds(Builder $query, int $limit): Collection
    {
        $pivot = random_int(1, max(1, (int) AppUser::query()->max('id')));

        $ids = (clone $query)->where('id', '>=', $pivot)->orderBy('id')->limit($limit)->pluck('id');

        if ($ids->count() < $limit) {
            $ids = $ids->concat(
                (clone $query)
                    ->where('id', '<', $pivot)
                    ->orderBy('id')
                    ->limit($limit - $ids->count())
                    ->pluck('id')
            );
        }

        return $ids;
    }

    private function query(AppUser $member, string $scope): Builder
    {
        $preferences = $member->preferences;

        return AppUser::query()
            ->select('id')
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
            ->when($scope === self::SCOPE_CITY, fn ($q) => $q->where('city_id', $member->city_id))
            ->when($scope === self::SCOPE_RADIUS, fn (Builder $q) => $this->withinRadius($q, $member));
    }

    /**
     * Restrict to candidates within the member's radius.
     *
     * The box is the cheap part and does the work: it is a range over indexed
     * columns, and it removes everybody outside a square drawn around the
     * member. The haversine then trims the square's corners down to a circle,
     * over the few rows that survived rather than the whole table.
     *
     * Candidates with no coordinates are excluded here — an unknown location
     * cannot be shown to satisfy a radius. withFallback() is what rescues a
     * member whose area is too sparse to fill a deck.
     */
    private function withinRadius(Builder $query, AppUser $member): Builder
    {
        $radius = $this->radiusFor($member);
        $latitude = (float) $member->last_latitude;
        $longitude = (float) $member->last_longitude;

        $latitudeDelta = $radius / self::KM_PER_DEGREE;

        // A degree of longitude shrinks towards the poles. The floor keeps the
        // box from exploding to the whole globe for somebody near one.
        $longitudeDelta = $radius / (self::KM_PER_DEGREE * max(0.01, cos(deg2rad($latitude))));

        return $query
            ->whereNotNull('last_latitude')
            ->whereNotNull('last_longitude')
            ->whereBetween('last_latitude', [$latitude - $latitudeDelta, $latitude + $latitudeDelta])
            ->whereBetween('last_longitude', [$longitude - $longitudeDelta, $longitude + $longitudeDelta])
            ->whereRaw(
                // least/greatest keeps acos inside its domain: floating point
                // can push the cosine a hair past 1 and turn the row into NULL.
                sprintf(
                    '(%d * acos(least(1.0, greatest(-1.0, cos(radians(?)) * cos(radians(last_latitude)) * cos(radians(last_longitude) - radians(?)) + sin(radians(?)) * sin(radians(last_latitude)))))) <= ?',
                    self::EARTH_RADIUS_KM,
                ),
                [$latitude, $longitude, $latitude, $radius],
            );
    }
}
