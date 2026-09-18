<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Swipes, matches, conversations and messages.
 *
 * Two decisions shape the realism of every downstream screen:
 *
 *  1. Matches are DERIVED, never invented. A second pass over `swipes` finds
 *     genuine mutual likes. That means the match rate, the like-to-match ratio
 *     and the "who did they match with" screens are all internally consistent —
 *     you can click from a match back to the two swipes that caused it.
 *
 *  2. Likes are desirability-weighted, so the top decile of profiles receives
 *     roughly 40% of all likes. A uniform model would put that number at 10%
 *     and the concentration gauge would look healthy when the real one never is.
 */
class BehaviourSeeder extends Seeder
{
    public function __construct(private readonly float $scale = 1.0) {}

    public function run(): void
    {
        $faker = fake();
        $faker->seed(config('veyra.seed.faker_seed', 20260917) + 2);

        $candidates = DB::table('app_users')
            ->select('id', 'city_id', 'gender', 'first_swipe_at', 'created_at', 'account_status')
            ->whereNotNull('first_swipe_at')
            ->get();

        if ($candidates->isEmpty()) {
            $this->command?->warn('No members with a first swipe; skipping behaviour graph.');

            return;
        }

        $ids = $candidates->pluck('id')->all();
        $cityById = $candidates->pluck('city_id', 'id')->all();

        /*
         * Latent desirability, Pareto-shaped.
         *
         * The exponent sets how unequally attention is distributed. Tuned so the
         * top decile of profiles receives roughly 40% of all likes — the real
         * figure reported across dating platforms, and the number that makes the
         * concentration gauge worth looking at. A uniform model would put it at
         * 10% and the marketplace would look healthy when it never is.
         */
        $desirability = [];
        foreach ($ids as $id) {
            $desirability[$id] = $faker->randomFloat(4, 0, 1) ** 4.0;
        }

        /*
         * Pools are built PER CITY, not globally.
         *
         * A deck shows you the same local pool over and over, which is why real
         * match rates are a few percent rather than near zero. Drawing targets
         * uniformly from the whole member base makes any two people reciprocating
         * vanishingly unlikely, and the match rate collapses.
         */
        $globalPool = $this->buildWeightedPool($ids, $desirability);
        $cityPools = [];

        foreach ($candidates->groupBy('city_id') as $cityId => $members) {
            $cityPools[(string) $cityId] = $this->buildWeightedPool(
                $members->pluck('id')->all(),
                $desirability,
            );
        }

        $swipes = $this->seedSwipes($faker, $candidates, $globalPool, $cityPools);
        $this->command?->info("Seeded {$swipes} swipes.");

        $reciprocated = $this->seedReciprocalLikes($faker);
        $this->command?->info("Seeded {$reciprocated} reciprocal likes.");

        $matches = $this->deriveMatches($cityById);
        $this->command?->info("Derived {$matches} matches from mutual likes.");

        $conversations = $this->seedConversations($faker);
        $this->command?->info("Seeded {$conversations} conversations.");

        $messages = $this->seedMessages($faker);
        $this->command?->info("Seeded {$messages} messages.");

        $blocks = $this->seedBlocks($faker, $ids);
        $this->command?->info("Seeded {$blocks} blocks.");

        $devices = $this->seedDevices($faker, $ids);
        $this->command?->info("Seeded {$devices} devices.");

        $logins = $this->seedLogins($faker);
        $this->command?->info("Seeded {$logins} member sign-ins.");
    }

    /**
     * A sampling pool where high-desirability members appear many times, so a
     * uniform draw over the pool produces a skewed draw over members.
     *
     * @param  array<int, int>  $ids
     * @param  array<int, float>  $desirability
     * @return array<int, int>
     */
    private function buildWeightedPool(array $ids, array $desirability): array
    {
        $pool = [];

        foreach ($ids as $id) {
            // 1-80 slots. The exponent above means most members sit at 1, and a
            // small minority take a large share of the pool.
            $slots = max(1, (int) round($desirability[$id] * 80));

            for ($i = 0; $i < $slots; $i++) {
                $pool[] = $id;
            }
        }

        shuffle($pool);

        return $pool;
    }

    /**
     * @param  array<int, int>  $globalPool
     * @param  array<string, array<int, int>>  $cityPools
     */
    private function seedSwipes($faker, $candidates, array $globalPool, array $cityPools): int
    {
        $globalSize = count($globalPool);
        $rows = [];
        $total = 0;

        $bar = $this->command?->getOutput()->createProgressBar($candidates->count());
        $bar?->start();

        foreach ($candidates as $member) {
            $start = Carbon::parse($member->first_swipe_at);
            $count = $this->swipeCount($faker);

            // Track targets locally: the unique index on (swiper, target) would
            // otherwise reject the batch and lose 1,000 good rows with it.
            $seen = [];

            $localPool = $cityPools[(string) $member->city_id] ?? [];
            $localSize = count($localPool);

            for ($i = 0; $i < $count; $i++) {
                // 88% local, mirroring a distance-limited deck.
                // Retried a few times: in a small city pool a single draw often
                // lands on somebody already swiped, and giving up immediately
                // would silently halve the swipe volume.
                $target = null;

                for ($attempt = 0; $attempt < 6; $attempt++) {
                    $pick = $localSize > 1 && $faker->boolean(88)
                        ? $localPool[$faker->numberBetween(0, $localSize - 1)]
                        : $globalPool[$faker->numberBetween(0, $globalSize - 1)];

                    if ($pick !== $member->id && ! isset($seen[$pick])) {
                        $target = $pick;
                        break;
                    }
                }

                if ($target === null) {
                    continue;
                }

                $seen[$target] = true;

                $action = $faker->randomFloat(4, 0, 1) < 0.345
                    ? ($faker->boolean(7) ? 'superlike' : 'like')
                    : 'pass';

                $rows[] = [
                    'app_user_id' => $member->id,
                    'target_app_user_id' => $target,
                    'action' => $action,
                    'source' => $faker->boolean(88) ? 'deck' : ($faker->boolean(60) ? 'likes_you' : 'profile'),
                    'is_match' => false,
                    'created_at' => $start->copy()->addMinutes($faker->numberBetween(0, 60 * 24 * 300)),
                ];

                $total++;

                if (count($rows) >= 2000) {
                    DB::table('swipes')->insertOrIgnore($rows);
                    $rows = [];
                }
            }

            $bar?->advance();
        }

        if ($rows !== []) {
            DB::table('swipes')->insertOrIgnore($rows);
        }

        $bar?->finish();
        $this->command?->newLine(2);

        return $total;
    }

    /**
     * Make a share of existing likes reciprocated.
     *
     * Matches stay genuinely derived — each one still comes from two real swipe
     * rows, and clicking through from a match to its swipes works. What is
     * seeded deliberately is the RECIPROCATION RATE, because leaving it to
     * chance produces a match rate near zero: two specific people independently
     * choosing each other out of a large pool is rare, whereas a real deck keeps
     * re-showing a small compatible set until they do.
     *
     * ~12% of likes coming back mirrors reported industry match rates.
     */
    private function seedReciprocalLikes($faker): int
    {
        $likes = DB::table('swipes')
            ->whereIn('action', ['like', 'superlike'])
            ->inRandomOrder()
            ->limit((int) round(DB::table('swipes')->whereIn('action', ['like', 'superlike'])->count() * 0.12))
            ->get(['app_user_id', 'target_app_user_id', 'created_at']);

        $rows = [];
        $total = 0;

        foreach ($likes as $like) {
            $rows[] = [
                'app_user_id' => $like->target_app_user_id,
                'target_app_user_id' => $like->app_user_id,
                'action' => $faker->boolean(8) ? 'superlike' : 'like',
                'source' => $faker->boolean(55) ? 'likes_you' : 'deck',
                'is_match' => false,
                // The reply-like lands after the original, which is what makes
                // the derived matched_at meaningful.
                'created_at' => Carbon::parse($like->created_at)->addMinutes($faker->numberBetween(5, 20160)),
            ];

            $total++;

            if (count($rows) >= 2000) {
                // insertOrIgnore: the pair may already have swiped back for real.
                DB::table('swipes')->insertOrIgnore($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('swipes')->insertOrIgnore($rows);
        }

        return $total;
    }

    /** Pareto: median ~22, p90 ~140, a long tail of mass swipers to flag. */
    /**
     * Swipes per member.
     *
     * This and messageCount() decide most of the database's size, so the tail is
     * kept only as long as it has to be. RiskEngine flags a swipe velocity
     * outlier above 400, so the top tier sits just past that line rather than at
     * the 1,800 a real power user would rack up: the factor still fires, on a
     * realistic handful of accounts, without those accounts alone contributing
     * more rows than everybody else combined.
     */
    private function swipeCount($faker): int
    {
        $roll = $faker->randomFloat(6, 0, 1);

        return match (true) {
            $roll > 0.995 => $faker->numberBetween(410, 520),
            $roll > 0.97 => $faker->numberBetween(90, 180),
            $roll > 0.90 => $faker->numberBetween(40, 90),
            $roll > 0.65 => $faker->numberBetween(16, 40),
            $roll > 0.30 => $faker->numberBetween(6, 16),
            default => $faker->numberBetween(1, 6),
        };
    }

    /**
     * Find genuine mutual likes and create one match per pair.
     *
     * Done in SQL because it is a self-join over hundreds of thousands of rows;
     * pulling that into PHP would be minutes rather than seconds.
     *
     * @param  array<int, int|null>  $cityById
     */
    private function deriveMatches(array $cityById): int
    {
        $pairs = DB::table('swipes as a')
            ->join('swipes as b', function ($join): void {
                $join->on('a.target_app_user_id', '=', 'b.app_user_id')
                    ->on('a.app_user_id', '=', 'b.target_app_user_id');
            })
            ->whereIn('a.action', ['like', 'superlike'])
            ->whereIn('b.action', ['like', 'superlike'])
            // Canonical ordering, so each pair is produced exactly once.
            ->whereColumn('a.app_user_id', '<', 'b.app_user_id')
            ->select(
                'a.app_user_id as one',
                'b.app_user_id as two',
                'a.created_at as a_at',
                'b.created_at as b_at',
            )
            ->get();

        $rows = [];
        $total = 0;

        foreach ($pairs as $pair) {
            // The match happens when the SECOND like lands.
            $matchedAt = max(
                Carbon::parse($pair->a_at),
                Carbon::parse($pair->b_at),
            );

            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'app_user_one_id' => $pair->one,
                'app_user_two_id' => $pair->two,
                'matched_at' => $matchedAt,
                'status' => 'active',
                'same_city' => ($cityById[$pair->one] ?? null) !== null
                    && ($cityById[$pair->one] ?? null) === ($cityById[$pair->two] ?? null),
                'messages_count' => 0,
                'created_at' => $matchedAt,
                'updated_at' => $matchedAt,
            ];

            $total++;

            if (count($rows) >= 1000) {
                DB::table('matches')->insertOrIgnore($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('matches')->insertOrIgnore($rows);
        }

        // Back-fill the flag on the swipes that caused each match, so the swipe
        // history and the match list agree.
        DB::statement('
            UPDATE swipes s
            JOIN matches m
              ON (s.app_user_id = m.app_user_one_id AND s.target_app_user_id = m.app_user_two_id)
              OR (s.app_user_id = m.app_user_two_id AND s.target_app_user_id = m.app_user_one_id)
            SET s.is_match = 1
        ');

        // A minority of matches do not survive.
        DB::table('matches')->inRandomOrder()->limit((int) (DB::table('matches')->count() * 0.16))
            ->update(['status' => 'unmatched']);

        return $total;
    }

    private function seedConversations($faker): int
    {
        // 62% of matches produce a conversation.
        $matches = DB::table('matches')->select('id', 'matched_at')->get();
        $rows = [];
        $participants = [];
        $total = 0;

        foreach ($matches as $match) {
            if (! $faker->boolean(62)) {
                continue;
            }

            $startedAt = Carbon::parse($match->matched_at)->addMinutes($faker->numberBetween(2, 2880));

            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'match_id' => $match->id,
                'started_at' => $startedAt,
                'last_message_at' => $startedAt,
                'messages_count' => 0,
                'is_flagged' => false,
                'risk_score' => 0,
                'status' => 'open',
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ];

            $total++;

            if (count($rows) >= 1000) {
                DB::table('conversations')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('conversations')->insert($rows);
        }

        // Participants are derived from the match's two sides.
        DB::statement('
            INSERT INTO conversation_participants (conversation_id, app_user_id, unread_count, muted)
            SELECT c.id, m.app_user_one_id, 0, 0 FROM conversations c JOIN matches m ON m.id = c.match_id
        ');
        DB::statement('
            INSERT INTO conversation_participants (conversation_id, app_user_id, unread_count, muted)
            SELECT c.id, m.app_user_two_id, 0, 0 FROM conversations c JOIN matches m ON m.id = c.match_id
        ');

        return $total;
    }

    private function seedMessages($faker): int
    {
        $conversations = DB::table('conversations as c')
            ->join('matches as m', 'm.id', '=', 'c.match_id')
            ->select('c.id', 'c.started_at', 'm.app_user_one_id', 'm.app_user_two_id')
            ->get();

        $openers = $this->openers();
        $replies = $this->replies();
        $rows = [];
        $total = 0;

        $bar = $this->command?->getOutput()->createProgressBar($conversations->count());
        $bar?->start();

        foreach ($conversations as $conversation) {
            $count = $this->messageCount($faker);
            $cursor = Carbon::parse($conversation->started_at);

            // Whoever sends first alternates realistically rather than strictly.
            $sender = $faker->boolean() ? $conversation->app_user_one_id : $conversation->app_user_two_id;
            $other = $sender === $conversation->app_user_one_id
                ? $conversation->app_user_two_id
                : $conversation->app_user_one_id;

            for ($i = 0; $i < $count; $i++) {
                $type = $this->messageType($faker);
                $body = $type === 'text'
                    ? ($i === 0 ? $faker->randomElement($openers) : $faker->randomElement($replies))
                    : null;

                // Contact-sharing concentrates in opening messages, which is
                // exactly the pattern the off-platform risk factor looks for.
                $containsContact = $type === 'text' && $faker->boolean($i < 2 ? 9 : 4);
                $containsLink = $type === 'text' && $faker->boolean(3);

                if ($containsContact && $body !== null) {
                    $body .= $faker->randomElement([
                        ' whats your insta?',
                        ' add me on whatsapp '.$faker->numerify('0## ### ####'),
                        ' lets move to telegram, im @'.$faker->userName(),
                    ]);
                }

                $flagged = $faker->boolean(1.9);

                $rows[] = [
                    'uuid' => (string) Str::uuid(),
                    'conversation_id' => $conversation->id,
                    'sender_app_user_id' => $sender,
                    'type' => $type,
                    'body' => $body,
                    'media_path' => $type === 'text' || $type === 'system' ? null : 'messages/placeholder',
                    'contains_link' => $containsLink,
                    'contains_contact_info' => $containsContact,
                    'is_flagged' => $flagged,
                    'flag_labels' => $flagged ? json_encode(['harassment' => $faker->randomFloat(2, 0.5, 0.95)]) : null,
                    'moderation_status' => $flagged ? 'flagged' : ($faker->boolean(0.4) ? 'removed' : 'none'),
                    'read_at' => $faker->boolean(80) ? $cursor->copy()->addMinutes($faker->numberBetween(1, 600)) : null,
                    'created_at' => $cursor,
                ];

                $total++;

                // Replies come fast; new topics come slowly.
                $cursor = $cursor->copy()->addMinutes($faker->boolean(70)
                    ? $faker->numberBetween(1, 45)
                    : $faker->numberBetween(60, 2880));

                [$sender, $other] = $faker->boolean(72) ? [$other, $sender] : [$sender, $other];

                if (count($rows) >= 2000) {
                    DB::table('messages')->insert($rows);
                    $rows = [];
                }
            }

            $bar?->advance();
        }

        if ($rows !== []) {
            DB::table('messages')->insert($rows);
        }

        $bar?->finish();
        $this->command?->newLine(2);

        $this->backfillCounters();

        return $total;
    }

    /**
     * Per-conversation length. 31% are the dreaded one-and-done, which is the
     * single most important fact about dating-app messaging.
     */
    /**
     * Messages per conversation.
     *
     * Same reasoning as swipeCount(): the 300-message threshold RiskEngine uses
     * for a message velocity outlier is per sender across every conversation, so
     * a ceiling of 90 here still lets a prolific account cross it while keeping
     * the messages table an order of magnitude smaller.
     */
    private function messageCount($faker): int
    {
        $roll = $faker->randomFloat(4, 0, 1);

        /*
         * The floor is 4 rather than 1 because the case evidence pane shows a
         * reported message with ten messages of context either side. A thread
         * two messages long renders that pane as the report itself and nothing
         * around it, which is the one thing the pane exists not to do.
         */
        return match (true) {
            $roll > 0.96 => $faker->numberBetween(41, 90),
            $roll > 0.72 => $faker->numberBetween(15, 40),
            $roll > 0.34 => $faker->numberBetween(8, 14),
            default => $faker->numberBetween(4, 7),
        };
    }

    private function messageType($faker): string
    {
        $roll = $faker->randomFloat(4, 0, 1);

        return match (true) {
            $roll > 0.99 => 'voice',
            $roll > 0.97 => 'gif',
            $roll > 0.93 => 'image',
            default => 'text',
        };
    }

    /**
     * Aggregate counters back onto matches and conversations.
     *
     * One UPDATE per table rather than per row — the difference between a few
     * seconds and several minutes.
     */
    private function backfillCounters(): void
    {
        DB::statement('
            UPDATE conversations c
            JOIN (
                SELECT conversation_id,
                       COUNT(*) AS total,
                       MIN(created_at) AS first_at,
                       MAX(created_at) AS last_at,
                       MAX(is_flagged) AS any_flagged
                FROM messages GROUP BY conversation_id
            ) agg ON agg.conversation_id = c.id
            SET c.messages_count = agg.total,
                c.started_at = agg.first_at,
                c.last_message_at = agg.last_at,
                c.is_flagged = agg.any_flagged
        ');

        DB::statement('
            UPDATE matches m
            JOIN conversations c ON c.match_id = m.id
            SET m.messages_count = c.messages_count,
                m.first_message_at = c.started_at,
                m.last_message_at = c.last_message_at
        ');

        /*
         * A reply is the first message from the OTHER party — the metric that
         * actually matters. Matches alone are vanity; a reply is a conversation.
         */
        DB::statement('
            UPDATE matches m
            JOIN conversations c ON c.match_id = m.id
            JOIN (
                SELECT x.conversation_id, MIN(x.created_at) AS reply_at
                FROM messages x
                JOIN (
                    SELECT conversation_id, sender_app_user_id, MIN(created_at) AS opened_at
                    FROM messages GROUP BY conversation_id, sender_app_user_id
                ) opener ON opener.conversation_id = x.conversation_id
                WHERE x.sender_app_user_id != opener.sender_app_user_id
                GROUP BY x.conversation_id
            ) r ON r.conversation_id = c.id
            SET m.first_reply_at = r.reply_at
        ');
    }

    private function seedBlocks($faker, array $ids): int
    {
        $rows = [];
        $total = 0;
        $count = (int) round(count($ids) * 0.34);

        /*
         * A deliberate harassment cluster: a handful of accounts blocked by many
         * others, so "blocked by 8+" has rows to surface.
         *
         * Sized against the population, not fixed. 26 targets each blocked by
         * 8-16 people is a rounding error at 12,000 members and half the member
         * base at 50 — which does not read as a harassment cluster, it reads as
         * a platform where everybody blocks everybody, and it drags the whole
         * risk distribution into the high band with it.
         */
        $clusterSize = min(26, max(3, (int) round(count($ids) * 0.02)));
        $targets = $faker->randomElements($ids, min($clusterSize, count($ids)));

        foreach ($targets as $target) {
            foreach ($faker->randomElements($ids, $faker->numberBetween(8, 16)) as $blocker) {
                if ($blocker === $target) {
                    continue;
                }

                $rows[] = [
                    'app_user_id' => $blocker,
                    'blocked_app_user_id' => $target,
                    'reason' => $faker->randomElement(['harassment', 'spam', 'made me uncomfortable', null]),
                    'created_at' => now()->subDays($faker->numberBetween(1, 200)),
                ];
                $total++;
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $blocker = $faker->randomElement($ids);
            $blocked = $faker->randomElement($ids);

            if ($blocker === $blocked) {
                continue;
            }

            $rows[] = [
                'app_user_id' => $blocker,
                'blocked_app_user_id' => $blocked,
                'reason' => $faker->randomElement(['harassment', 'spam', 'not interested', null]),
                'created_at' => now()->subDays($faker->numberBetween(1, 400)),
            ];
            $total++;
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('blocks')->insertOrIgnore($chunk);
        }

        return $total;
    }

    private function seedDevices($faker, array $ids): int
    {
        $rows = [];
        $total = 0;

        // Shared fingerprints: ban evasion and duplicate signups.
        $sharedHashes = [];
        for ($i = 0; $i < 34; $i++) {
            $sharedHashes[] = hash('sha256', "shared-device-{$i}");
        }

        $sharedOwners = $faker->randomElements($ids, min(140, count($ids)));
        $sharedIndex = [];
        foreach ($sharedOwners as $position => $owner) {
            $sharedIndex[$owner] = $sharedHashes[$position % count($sharedHashes)];
        }

        foreach ($ids as $id) {
            $deviceCount = $faker->boolean(35) ? 2 : 1;

            for ($d = 0; $d < $deviceCount; $d++) {
                $rows[] = [
                    'app_user_id' => $id,
                    'fingerprint_hash' => $d === 0 && isset($sharedIndex[$id])
                        ? $sharedIndex[$id]
                        : hash('sha256', "device:{$id}:{$d}"),
                    'platform' => $faker->randomElement(['ios', 'android', 'web']),
                    'os_version' => $faker->numerify('##.#'),
                    'app_version' => $faker->randomElement(['2.4.0', '2.5.1', '2.6.0', '2.6.2']),
                    'push_token' => $faker->boolean(80) ? $faker->sha256() : null,
                    'last_seen_at' => now()->subDays($faker->numberBetween(0, 120)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $total++;
            }

            if (count($rows) >= 1000) {
                DB::table('devices')->insertOrIgnore($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('devices')->insertOrIgnore($rows);
        }

        return $total;
    }

    /**
     * Member sign-in history, for System -> Member sign-ins.
     *
     * Only members seen in the last 30 days get rows, and the most recent row is
     * pinned to last_active_at so the log and the member record agree. Seeding
     * every member's whole history would be the single largest table here for a
     * screen that is read a page at a time.
     *
     * The failures are not noise: a handful of accounts get a burst of them from
     * one address, which is what the failed-sign-ins filter exists to surface.
     */
    private function seedLogins($faker): int
    {
        $members = DB::table('app_users')
            ->where('last_active_at', '>=', now()->subDays(30))
            ->pluck('last_active_at', 'id');

        if ($members->isEmpty()) {
            return 0;
        }

        $devices = DB::table('devices')
            ->whereIn('app_user_id', $members->keys())
            ->get(['id', 'app_user_id'])
            ->groupBy('app_user_id')
            ->map(fn ($group) => $group->pluck('id')->all())
            ->all();

        // A few accounts under credential-stuffing, so the failure filter and the
        // "Failed (30d)" stat both return something.
        $stuffed = $faker->randomElements($members->keys()->all(), min(6, $members->count()));
        $stuffedIndex = array_flip($stuffed);

        $rows = [];
        $total = 0;

        foreach ($members as $id => $lastActive) {
            $lastActive = Carbon::parse($lastActive);
            $deviceIds = $devices[$id] ?? [];

            $attempts = [['at' => $lastActive, 'ok' => true]];

            foreach (range(1, $faker->numberBetween(0, 2)) as $ignored) {
                $attempts[] = [
                    'at' => $lastActive->copy()->subDays($faker->numberBetween(1, 29)),
                    'ok' => $faker->boolean(94),
                ];
            }

            if (isset($stuffedIndex[$id])) {
                $burstAt = $lastActive->copy()->subDays($faker->numberBetween(1, 20));
                $burstIp = $faker->ipv4();

                foreach (range(1, $faker->numberBetween(5, 9)) as $n) {
                    $attempts[] = [
                        'at' => $burstAt->copy()->addSeconds($n * $faker->numberBetween(20, 90)),
                        'ok' => false,
                        'ip' => $burstIp,
                    ];
                }
            }

            foreach ($attempts as $attempt) {
                $rows[] = [
                    'app_user_id' => $id,
                    // A failed attempt has no session, so it carries no device.
                    'device_id' => $attempt['ok'] && $deviceIds !== []
                        ? $faker->randomElement($deviceIds)
                        : null,
                    'ip_address' => $attempt['ip'] ?? $faker->ipv4(),
                    'country_code' => $faker->randomElement(['GB', 'US', 'IE', 'ES', 'DE', 'FR', 'NL', 'PT']),
                    'succeeded' => $attempt['ok'],
                    'created_at' => $attempt['at'],
                ];

                $total++;

                if (count($rows) >= 1000) {
                    DB::table('app_user_logins')->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            DB::table('app_user_logins')->insert($rows);
        }

        return $total;
    }

    /** @return array<int, string> */
    private function openers(): array
    {
        return [
            'Hey! How is your week going?',
            'Okay, your profile made me laugh. Tell me more about the Lisbon thing.',
            'Hi :) what are you up to this weekend?',
            'Genuine question: best coffee in the city, go.',
            'We matched, so clearly you have excellent taste.',
            'Hello! Your dog is very good. You seem alright too.',
            'What is the last thing that properly made you laugh?',
            'Hey, I have a very important question about pizza toppings.',
            'Hi! How long have you been in the city?',
            'Your photos suggest you actually go outside. Impressive.',
            'Hey there. Sell me on your favourite album.',
            'Good evening! Rescue me from doing laundry.',
        ];
    }

    /** @return array<int, string> */
    private function replies(): array
    {
        return [
            'Ha, fair enough.',
            'Honestly same.',
            'That is a strong opinion and I respect it.',
            'Work has been a lot, but the weekend looks better.',
            'I would absolutely be up for that.',
            'Okay you have convinced me.',
            'What about you?',
            'Sorry, only just seen this!',
            'That sounds amazing actually.',
            'I have never tried it, is it worth it?',
            'Tuesday works for me if you are free?',
            'Deal. But I am picking the place.',
            'Not going to lie, that is impressive.',
            'How did that even happen?',
            'Ok tell me the whole story.',
            'Haha stop.',
            'Where would you go if you could go anywhere?',
            'I am definitely the person who reads the plaques.',
        ];
    }
}
