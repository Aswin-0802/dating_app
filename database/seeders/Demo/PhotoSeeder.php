<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Services\Media\PlaceholderPhotoGenerator;
use App\Services\Media\StockPortraitLibrary;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Member photos, plus the planted duplicate rings.
 *
 * The planted duplicates are the point of this seeder. Without them the
 * verification review screen's hero signal — "this face is on 4 other accounts"
 * — has nothing to show, and the duplicate-detection query is untested.
 */
class PhotoSeeder extends Seeder
{
    /** photos-per-member distribution, as weights */
    private const COUNT_MIX = [0 => 6, 1 => 9, 2 => 13, 3 => 22, 4 => 20, 5 => 15, 6 => 9, 7 => 6];

    /** Distinct faces shared across accounts (the stolen-identity rings). */
    private const FACE_RINGS = 18;

    /** Distinct images reused byte-for-byte across accounts. */
    private const HASH_RINGS = 24;

    public function run(): void
    {
        $faker = fake();
        $faker->seed(config('platform.seed.faker_seed', 20260917) + 1);

        $mode = config('platform.seed.photos', 'stock');
        $genders = DB::table('app_users')->pluck('gender', 'id')->all();
        $userIds = array_keys($genders);

        $generator = null;
        $stock = null;

        if ($mode === 'stock') {
            $stock = new StockPortraitLibrary;
            $this->command?->info('Fetching demo portraits…');

            if ($stock->prepare() === 0) {
                // Offline or blocked: placeholders rather than no photos at all.
                $this->command?->warn('No portraits could be downloaded; using generated placeholders instead.');
                $stock = null;
                $mode = 'generated';
            }
        }

        if ($mode === 'generated') {
            $generator = new PlaceholderPhotoGenerator;
            $this->command?->info('Rendering the shared image pool…');
            $generator->buildPool(900);
        }

        /*
         * Pick the accounts that will share a face, and the accounts that will
         * share a literal file. These are what the review screen surfaces.
         *
         * The ring counts are ceilings rather than targets: 18 rings of up to 6
         * need 108 accounts to draw from, so at 50 members every single account
         * ends up in a ring and "same face on 4 accounts" stops being an anomaly
         * worth flagging. ringCount() keeps the planted rings to a quarter of
         * the population at any scale.
         */
        $faceRings = $this->buildGenderedRings($faker, $genders, $this->ringCount(self::FACE_RINGS, count($userIds), 6), 2, 6);
        $hashRings = $this->buildGenderedRings($faker, $genders, $this->ringCount(self::HASH_RINGS, count($userIds), 5), 2, 5);

        $faceByUser = $this->indexRings($faceRings, fn (int $ring): string => hash('sha256', "face-ring-{$ring}"));
        $hashByUser = $this->indexRings($hashRings, fn (int $ring): string => substr(md5("hash-ring-{$ring}"), 0, 16));

        $rows = [];
        $total = 0;

        $bar = $this->command?->getOutput()->createProgressBar(count($userIds));
        $bar?->start();

        $nextPortrait = [];

        foreach ($userIds as $userId) {
            $count = (int) $this->weighted($faker, self::COUNT_MIX);

            // A real portrait is one person; a second photo from the pool would
            // be somebody else. Stock members get one photo, or none.
            if ($stock !== null) {
                $count = min(1, $count);
            }

            for ($position = 0; $position < $count; $position++) {
                // Members in a ring share their PRIMARY photo — that is the one
                // the comparator and the linked-accounts drawer display.
                $inFaceRing = $position === 0 && isset($faceByUser[$userId]);
                $inHashRing = $position === 0 && isset($hashByUser[$userId]);

                $file = ['path' => null, 'thumb_path' => null, 'width' => null, 'height' => null, 'bytes' => null];

                if ($stock !== null) {
                    $gender = (string) $genders[$userId];

                    // Ring members share ONE portrait, which is what a stolen-
                    // photo ring looks like: the same person on several accounts.
                    $ringKey = $inFaceRing ? $faceByUser[$userId] : ($inHashRing ? $hashByUser[$userId] : null);
                    $index = $ringKey !== null
                        ? (int) sprintf('%u', crc32($ringKey))
                        : ($nextPortrait[$gender] = ($nextPortrait[$gender] ?? -1) + 1);

                    // A ring is one gender (see buildGenderedRings), so the
                    // shared portrait at least matches every name on it.
                    $file = $stock->pick($gender, $index) ?? $file;
                } elseif ($generator !== null) {
                    // A ring member is given the SAME pool image, so the four
                    // accounts genuinely look identical on screen.
                    $override = $inFaceRing
                        ? (int) sprintf('%u', crc32($faceByUser[$userId]))
                        : ($inHashRing ? (int) sprintf('%u', crc32($hashByUser[$userId])) : null);

                    $file = $generator->assign($userId, $position, $override);
                }

                $labels = $this->labels($faker);

                $rows[] = [
                    'uuid' => $faker->uuid(),
                    'app_user_id' => $userId,
                    'disk' => 'public',
                    'path' => $file['path'] ?? 'photos/placeholder.jpg',
                    'thumb_path' => $file['thumb_path'],
                    'position' => $position,
                    'is_primary' => $position === 0,
                    'width' => $file['width'],
                    'height' => $file['height'],
                    'bytes' => $file['bytes'],
                    'phash' => $inHashRing ? $hashByUser[$userId] : substr(md5("{$userId}:{$position}"), 0, 16),
                    'face_signature' => $inFaceRing
                        ? $faceByUser[$userId]
                        : hash('sha256', "face:{$userId}"),
                    'moderation_status' => $this->weighted($faker, [
                        'approved' => 89, 'pending' => 6, 'auto_flagged' => 3, 'rejected' => 2,
                    ]),
                    'moderation_labels' => json_encode($labels),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $total++;

                if (count($rows) >= 1000) {
                    DB::table('photos')->insert($rows);
                    $rows = [];
                }
            }

            $bar?->advance();
        }

        if ($rows !== []) {
            DB::table('photos')->insert($rows);
        }

        $bar?->finish();
        $this->command?->newLine(2);

        $ringAccounts = count($faceByUser);
        $ringsUsed = count($faceRings);
        $this->command?->info(
            "Seeded {$total} photos, including {$ringAccounts} accounts across {$ringsUsed} shared faces."
        );
    }

    /**
     * How many rings a population of this size can carry.
     *
     * A ring is only a signal if most accounts are not in one, so the planted
     * rings are capped at a quarter of the member base. The floor of three is
     * what keeps the duplicate-face drawer demonstrable at the tiny scale, where
     * a strict quarter would round down to one ring or none.
     */
    private function ringCount(int $ceiling, int $population, int $maxPerRing): int
    {
        return max(3, min($ceiling, (int) floor($population * 0.25 / $maxPerRing)));
    }

    /**
     * Rings drawn from one gender at a time, so the portrait four accounts
     * share can match the names on them. Half the rings are men, half women;
     * members of other genders are never in a ring.
     *
     * @param  array<int, string>  $genders  userId => gender
     * @return array<int, array<int, int>>
     */
    private function buildGenderedRings($faker, array $genders, int $ringCount, int $min, int $max): array
    {
        $rings = [];

        foreach (['woman', 'man'] as $slot => $gender) {
            $pool = array_keys(array_filter($genders, fn (string $g): bool => $g === $gender));
            $share = $slot === 0 ? (int) ceil($ringCount / 2) : (int) floor($ringCount / 2);

            foreach ($this->buildRings($faker, $pool, $share, $min, $max) as $members) {
                $rings[] = $members;
            }
        }

        return $rings;
    }

    /**
     * Group random accounts into rings of 2-6.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array<int, int>>
     */
    private function buildRings($faker, array $userIds, int $ringCount, int $min, int $max): array
    {
        $rings = [];
        $pool = $faker->randomElements($userIds, min(count($userIds), $ringCount * $max));
        $cursor = 0;

        for ($ring = 0; $ring < $ringCount; $ring++) {
            $size = $faker->numberBetween($min, $max);
            $members = array_slice($pool, $cursor, $size);
            $cursor += $size;

            if (count($members) < 2) {
                break;
            }

            $rings[$ring] = $members;
        }

        return $rings;
    }

    /**
     * @param  array<int, array<int, int>>  $rings
     * @return array<int, string> userId => signature
     */
    private function indexRings(array $rings, callable $signature): array
    {
        $index = [];

        foreach ($rings as $ring => $members) {
            foreach ($members as $userId) {
                $index[$userId] = $signature($ring);
            }
        }

        return $index;
    }

    /** @return array<string, float> */
    private function labels($faker): array
    {
        return [
            // A small tail of genuinely flagged content, so the moderation queue
            // and the blur-by-default control have something to act on.
            'nudity' => $faker->boolean(1.4) ? $faker->randomFloat(2, 0.71, 0.99) : $faker->randomFloat(2, 0, 0.3),
            'violence' => $faker->randomFloat(2, 0, 0.2),
            'minor_likelihood' => $faker->boolean(0.3) ? $faker->randomFloat(2, 0.51, 0.9) : $faker->randomFloat(2, 0, 0.2),
            'quality' => $faker->randomFloat(2, 0.4, 1.0),
        ];
    }

    /** @param array<string|int, float|int> $weights */
    private function weighted($faker, array $weights): string
    {
        $total = array_sum($weights);
        $roll = $faker->randomFloat(4, 0, $total);
        $cumulative = 0.0;

        foreach ($weights as $key => $weight) {
            $cumulative += $weight;

            if ($roll <= $cumulative) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }
}
