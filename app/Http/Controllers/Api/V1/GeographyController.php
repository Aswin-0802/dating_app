<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reference data for the location pickers: countries, states and a city
 * search. Everything here honours the same rule as the write paths
 * (City::selectable / App\Rules\SelectableCity), so the apps offer exactly
 * what sign-up accepts.
 */
class GeographyController extends Controller
{
    /** How many cities one search returns. Filtered, so a cap is a nudge to type more, not a wall. */
    public const CITY_LIMIT = 100;

    public function countries(): JsonResponse
    {
        return response()->json([
            'data' => Country::query()->where('is_active', true)->orderBy('name')->get(['iso2', 'name', 'dial_code']),
        ]);
    }

    /** ?country=IN lists that country's states; hidden ones are left out. */
    public function states(Request $request): JsonResponse
    {
        $data = $request->validate(['country' => ['nullable', 'string', 'size:2']]);

        return response()->json([
            'data' => State::query()
                ->where('is_active', true)
                ->when($data['country'] ?? null, fn ($q, $iso) => $q->whereHas('country', fn ($c) => $c->where('iso2', strtoupper($iso))))
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'code', 'country_id']),
        ]);
    }

    /**
     * A type-ahead, not a list.
     *
     * ?search= (two characters or more) matches the start of the name, which
     * the index on cities.name can serve; ?country= and ?state= narrow it.
     * At most CITY_LIMIT rows come back, with meta.truncated saying whether
     * there were more — a full list was how a member in an unlisted city
     * could never sign up and never learn why.
     */
    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'min:2', 'max:80'],
            'country' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'integer'],
        ], [
            'search.min' => 'Type at least two letters.',
        ]);

        $search = trim((string) ($data['search'] ?? ''));

        $cities = City::query()
            ->selectable()
            ->when($data['country'] ?? null, fn ($q, $iso) => $q->whereHas('country', fn ($c) => $c->where('iso2', strtoupper($iso))))
            ->when($data['state'] ?? null, fn ($q, $state) => $q->where('state_id', (int) $state))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', self::escapeLike($search).'%'))
            ->with('state:id,name')
            ->orderBy('name')
            ->limit(self::CITY_LIMIT + 1)
            ->get(['id', 'name', 'country_id', 'state_id']);

        $truncated = $cities->count() > self::CITY_LIMIT;

        return response()->json([
            'data' => $cities->take(self::CITY_LIMIT)->map(fn (City $city): array => [
                'id' => $city->id,
                'name' => $city->name,
                'country_id' => $city->country_id,
                'state' => $city->state?->name,
                'state_id' => $city->state_id,
            ])->values(),
            'meta' => ['limit' => self::CITY_LIMIT, 'truncated' => $truncated],
        ]);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
