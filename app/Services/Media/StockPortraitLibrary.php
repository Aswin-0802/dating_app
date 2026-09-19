<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Real portrait photography for seeded demo members.
 *
 * Downloads the Unsplash portraits listed in database/seeders/data/portraits.php
 * once, into the public disk, and serves them from there on every reseed. A
 * portrait that cannot be fetched (offline machine, removed photo) is skipped;
 * when none can be fetched the seeder falls back to generated placeholders, so
 * seeding never fails for want of a network.
 */
final class StockPortraitLibrary
{
    private const DIR = 'photos/_stock';

    /** @var array<string, array<int, array{path: string, thumb_path: string, width: int, height: int, bytes: int}>> */
    private array $available = [];

    public function __construct(private readonly string $disk = 'public') {}

    /**
     * Makes sure every portrait is on disk and returns how many are usable.
     */
    public function prepare(?callable $onProgress = null): int
    {
        $list = require database_path('seeders/data/portraits.php');
        $storage = Storage::disk($this->disk);
        $count = 0;

        foreach ($list as $group => $ids) {
            foreach ($ids as $id) {
                $path = self::DIR."/{$group}/{$id}.jpg";
                $thumbPath = self::DIR."/{$group}/{$id}-thumb.jpg";

                if (! $storage->exists($path) && ! $this->download($id, $path, 900, 1125)) {
                    $onProgress && $onProgress();

                    continue;
                }

                if (! $storage->exists($thumbPath)) {
                    $this->download($id, $thumbPath, 480, 600) || $storage->copy($path, $thumbPath);
                }

                $this->available[$group][] = [
                    'path' => $path,
                    'thumb_path' => $thumbPath,
                    'width' => 900,
                    'height' => 1125,
                    'bytes' => (int) $storage->size($path),
                ];
                $count++;

                $onProgress && $onProgress();
            }
        }

        return $count;
    }

    public function total(): int
    {
        return array_sum(array_map('count', $this->available));
    }

    /**
     * A portrait for a member of this gender.
     *
     * `$index` is a running counter per gender, so consecutive members get
     * different people and repeats only begin once the pool is exhausted.
     * Non-binary and other members draw from both groups.
     *
     * @return array{path: string, thumb_path: string, width: int, height: int, bytes: int}|null
     */
    public function pick(string $gender, int $index): ?array
    {
        $pool = match ($gender) {
            'woman', 'man' => $this->available[$gender] ?? [],
            default => [...($this->available['woman'] ?? []), ...($this->available['man'] ?? [])],
        };

        return $pool === [] ? null : $pool[$index % count($pool)];
    }

    private function download(string $id, string $path, int $width, int $height): bool
    {
        try {
            $response = Http::timeout(15)->retry(1, 500)->get(
                "https://images.unsplash.com/photo-{$id}",
                ['w' => $width, 'h' => $height, 'fit' => 'crop', 'crop' => 'faces', 'q' => 78, 'fm' => 'jpg'],
            );

            if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                return false;
            }

            Storage::disk($this->disk)->put($path, $response->body());

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
