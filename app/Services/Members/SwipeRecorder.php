<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Jobs\SendMemberPush;
use App\Models\AppUser;
use App\Models\Conversation;
use App\Models\MatchRecord;
use App\Models\Swipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a like, pass or superlike, and creates the match when it is mutual.
 *
 * Shared by the website and the mobile API.
 */
final class SwipeRecorder
{
    /**
     * @param  'like'|'pass'|'superlike'  $action
     * @return MatchRecord|null the new match, when this swipe completed one
     */
    public function record(AppUser $member, AppUser $target, string $action, string $source = 'deck'): ?MatchRecord
    {
        if ($target->id === $member->id) {
            throw ValidationException::withMessages(['target_id' => 'You cannot swipe on yourself.']);
        }

        return DB::transaction(function () use ($member, $target, $action, $source): ?MatchRecord {
            /*
             * The cap is counted and enforced inside the transaction, behind a
             * lock on the member's own row.
             *
             * Counting outside it let parallel requests all read the same total
             * before any of them had inserted, so the daily limit could be
             * walked straight past by sending swipes concurrently. The lock
             * only serialises one member against themselves, which is what a
             * person does anyway.
             */
            AppUser::query()->whereKey($member->id)->lockForUpdate()->first();

            $this->enforceDailyLikeLimit($member, $action);

            Swipe::query()->updateOrCreate(
                ['app_user_id' => $member->id, 'target_app_user_id' => $target->id],
                ['action' => $action, 'source' => $source, 'created_at' => now()],
            );

            if ($member->first_swipe_at === null) {
                $member->forceFill(['first_swipe_at' => now()])->saveQuietly();
            }

            if (! in_array($action, ['like', 'superlike'], true)) {
                return null;
            }

            // A match exists only when the other side has already liked back —
            // derived from real swipe rows, never asserted.
            $reciprocated = Swipe::query()
                ->where('app_user_id', $target->id)
                ->where('target_app_user_id', $member->id)
                ->whereIn('action', ['like', 'superlike'])
                ->exists();

            // A second like on somebody already matched must not create a
            // second match row for the same pair.
            if (! $reciprocated || MatchRecord::query()->involving($member)->involving($target)->exists()) {
                return null;
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

            /*
             * Both people hear about it, after commit.
             *
             * The template has been editable in the console from the start and
             * nothing referenced it, so "it's a match" was never delivered —
             * the moment the whole product is built around, silent.
             */
            DB::afterCommit(function () use ($member, $target): void {
                SendMemberPush::dispatch($member->id, 'match.new', ['name' => $target->display_name], route('member.matches'));
                SendMemberPush::dispatch($target->id, 'match.new', ['name' => $member->display_name], route('member.matches'));
            });

            return $match;
        });
    }

    /** Null for members whose plan includes unlimited likes. */
    public function likesLeftToday(AppUser $member): ?int
    {
        if ($member->hasPremiumFeature('unlimited_likes')) {
            return null;
        }

        return max(0, $this->dailyLimit() - $this->likesUsedToday($member));
    }

    /**
     * Free accounts get a daily like budget.
     *
     * A product rule about a calendar day, not burst protection — which is why
     * it lives here rather than in the rate limiter.
     */
    private function enforceDailyLikeLimit(AppUser $member, string $action): void
    {
        if (! in_array($action, ['like', 'superlike'], true) || $member->hasPremiumFeature('unlimited_likes')) {
            return;
        }

        $limit = $this->dailyLimit();

        if ($this->likesUsedToday($member) >= $limit) {
            throw ValidationException::withMessages([
                'action' => "You have used all {$limit} likes for today. They reset at midnight.",
            ]);
        }
    }

    private function dailyLimit(): int
    {
        return (int) platform_setting('matching.daily_like_limit_free', 100);
    }

    private function likesUsedToday(AppUser $member): int
    {
        return Swipe::query()
            ->where('app_user_id', $member->id)
            ->whereIn('action', ['like', 'superlike'])
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }
}
