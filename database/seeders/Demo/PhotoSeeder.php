<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Services\Media\PlaceholderPhotoGenerator;
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
        $faker->seed(config('veyra.seed.faker_seed', 20260917) + 1);

        $mode = config('veyra.seed.photos', 'generated');
        $userIds = DB::table('app_users')->pluck('id')->all();

        $generator = null;

        if ($mode === 'generated') {
            $generator = new PlaceholderPhotoGenerator();
            $this->command?->info('Rendering the shared image pool…');
            $generator->buildPool(900);
        }

        // Pick the accounts that will share a face, and the accounts that will
        // share a literal file. These are what the review screen surfaces.
        $faceRings = $this->buildRings($faker, $userIds, self::FACE_RINGS, 2, 6);
        $hashRings = $this->buildRings($faker, $userIds, self::HASH_RINGS, 2, 5);

        $faceByUser = $this->indexRings($faceRings, fn (int $ring): string => hash('sha256', "face-ring-{$ring}"));
        $hashByUser = $this->indexRings($hashRings, fn (int $ring): string => substr(md5("hash-ring-{$ring}"), 0, 16));

        $rows = [];
        $total = 0;

        $bar = $this->command?->getOutput()->createProgressBar(count($userIds));
        $bar?->start();

        foreach ($userIds as $userId) {
            $count = (int) $this->weighted($faker, self::COUNT_MIX);

            for ($position = 0; $position < $count; $position++) {
                // Members in a ring share their PRIMARY photo — that is the one
                // the comparator and the linked-accounts drawer display.
                $inFaceRing = $position === 0 && isset($faceByUser[$userId]);
                $inHashRing = $position === 0 && isset($hashByUser[$userId]);

                $file = ['path' => null, 'thumb_path' => null, 'width' => null, 'height' => null, 'bytes' => null];

                if ($generator !== null) {
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
        $this->command?->info(
            "Seeded {$total} photos, including {$ringAccounts} accounts across ".self::FACE_RINGS.' shared faces.'
        );
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
