<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AppUserResource;
use App\Http\Resources\Api\V1\MatchResource;
use App\Models\AppUser;
use App\Services\Members\DiscoveryDeck;
use App\Services\Members\SwipeRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The deck and swiping. The rules live in DiscoveryDeck and SwipeRecorder,
 * which the member website uses too.
 */
class SwipeController extends Controller
{
    public function deck(Request $request, DiscoveryDeck $deck): JsonResponse
    {
        $candidates = $deck->for($request->user(), (int) $request->integer('limit', 20));

        return response()->json([
            'data' => AppUserResource::collection($candidates),
            'meta' => ['count' => $candidates->count()],
        ]);
    }

    public function store(Request $request, SwipeRecorder $swipes): JsonResponse
    {
        $data = $request->validate([
            'target_id' => ['required', 'uuid', 'exists:app_users,uuid'],
            'action' => ['required', 'in:like,pass,superlike'],
            'source' => ['nullable', 'in:deck,likes_you,profile'],
        ]);

        $target = AppUser::query()->where('uuid', $data['target_id'])->firstOrFail();
        $match = $swipes->record($request->user(), $target, $data['action'], $data['source'] ?? 'deck');

        return response()->json([
            'is_match' => $match !== null,
            'match' => $match
                ? new MatchResource($match->load(['userOne.photos', 'userTwo.photos']))
                : null,
        ], 201);
    }
}
