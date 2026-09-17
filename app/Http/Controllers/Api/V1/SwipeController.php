<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AppUserResource;
use App\Http\Resources\Api\V1\MatchResource;
use App\Models\AppUser;
use App\Models\Conversation;
use App\Models\MatchRecord;
use App\Models\Swipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SwipeController extends Controller
{
    /**
     * The discovery deck.
     *
     * Excludes: yourself, anyone already swiped, anyone blocked in either
     * direction, and any account that is not active. Shadow-banned members are
     * excluded here — that removal from discovery IS the shadow ban, and it is
     * the only place it takes effect.
     */
    public function deck(Request $request): JsonResponse
    {
        $member = $request->user();
        $preferences = $member->preferences;
        $limit = min(30, max(1, (int) $request->integer('limit', 20)));

        $candidates = AppUser::query()
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
            ->when(! $preferences?->global_mode && $member->city_id, fn ($q) => $q->where('city_id', $member->city_id))
            ->with(['photos', 'profile', 'city', 'interests'])
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => AppUserResource::collection($candidates),
            'meta' => ['count' => $candidates->count()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_id' => ['required', 'uuid', 'exists:app_users,uuid'],
            'action' => ['required', 'in:like,pass,superlike'],
            'source' => ['nullable', 'in:deck,likes_you,profile'],
        ]);

        $member = $request->user();
        $target = AppUser::query()->where('uuid', $data['target_id'])->firstOrFail();

        if ($target->id === $member->id) {
            throw ValidationException::withMessages(['target_id' => 'You cannot swipe on yourself.']);
        }

        $this->enforceDailyLikeLimit($member, $data['action']);

        $result = DB::transaction(function () use ($member, $target, $data): array {
            Swipe::query()->updateOrCreate(
                ['app_user_id' => $member->id, 'target_app_user_id' => $target->id],
                [
                    'action' => $data['action'],
                    'source' => $data['source'] ?? 'deck',
                    'created_at' => now(),
                ],
            );

            if ($member->first_swipe_at === null) {
                $member->forceFill(['first_swipe_at' => now()])->saveQuietly();
            }

            if (! in_array($data['action'], ['like', 'superlike'], true)) {
                return ['is_match' => false, 'match' => null];
            }

            // A match exists only when the other side has already liked back —
            // derived from real swipe rows, never asserted.
            $reciprocated = Swipe::query()
                ->where('app_user_id', $target->id)
                ->where('target_app_user_id', $member->id)
                ->whereIn('action', ['like', 'superlike'])
                ->exists();

            if (! $reciprocated) {
                return ['is_match' => false, 'match' => null];
            }

            $match = MatchRecord::query()->create([
                'app_user_one_id' => $member->id,
                'app_user_two_id' => $target->id,
                'matched_at' => now(),
                'status' => 'active',
                'same_city' => $member->city_id !== null && $member->city_id === $target->city_id,
            ]);

            Swipe::query()
                ->where(fn ($q) => $q->where('app_user_id', $member->id)->where('target_app_user_id', $target->id))
                ->orWhere(fn ($q) => $q->where('app_user_id', $target->id)->where('target_app_user_id', $member->id))
                ->update(['is_match' => true]);

            $conversation = Conversation::query()->create([
                'match_id' => $match->id,
                'status' => 'open',
            ]);

            // Participants are what every ownership check reads. A conversation
            // created without them belongs to nobody, and both people in it are
            // refused access to their own thread.
            $conversation->participants()->attach([$member->id, $target->id]);

            foreach ([$member, $target] as $participant) {
                if ($participant->first_match_at === null) {
                    $participant->forceFill(['first_match_at' => now()])->saveQuietly();
                }
            }

            return ['is_match' => true, 'match' => $match];
        });

        return response()->json([
            'is_match' => $result['is_match'],
            'match' => $result['match']
                ? new MatchResource($result['match']->load(['userOne.photos', 'userTwo.photos']))
                : null,
        ], 201);
    }

    /**
     * Free accounts get a daily like budget.
     *
     * Enforced here rather than by the rate limiter: this is a product rule
     * about a calendar day, not a burst-protection rule about a minute.
     */
    private function enforceDailyLikeLimit(AppUser $member, string $action): void
    {
        if (! in_array($action, ['like', 'superlike'], true) || $member->is_premium) {
            return;
        }

        $limit = (int) veyra_setting('matching.daily_like_limit_free', 100);

        $used = Swipe::query()
            ->where('app_user_id', $member->id)
            ->whereIn('action', ['like', 'superlike'])
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($used >= $limit) {
            throw ValidationException::withMessages([
                'action' => "You have used all {$limit} likes for today. They reset at midnight.",
            ]);
        }
    }
}
