<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Generates demo profile imagery locally with GD. No network, no bundled assets.
 *
 * Two constraints shape this:
 *
 *  1. ~44,000 images at ~25ms each would be an 18-minute seed. So a POOL of base
 *     images is rendered once and assigned by seeded index; individual photos are
 *     a file copy, which is sub-millisecond.
 *
 *  2. The images must not look like real people. They are gradient plates with an
 *     abstract silhouette — recognisably placeholders, so nobody
 *     mistakes seeded data for a real member, while still giving the grid,
 *     comparator and duplicate-detection screens something to show.
 */
final class PlaceholderPhotoGenerator
{
    private const PHOTO_WIDTH = 640;

    private const PHOTO_HEIGHT = 800;

    private const SELFIE_SIZE = 600;

    private const THUMB_WIDTH = 160;

    private const THUMB_HEIGHT = 200;

    /** Hue pairs for the background gradient — on-brand but varied. */
    private const PALETTE = [
        [348, 320], [12, 350], [330, 300], [20, 45], [340, 15],
        [290, 320], [5, 30], [355, 285], [35, 15], [310, 340],
        [200, 250], [160, 190], [260, 300], [40, 70], [180, 220],
        [15, 340], [300, 260], [50, 25], [230, 280], [140, 170],
        [10, 60], [320, 355], [270, 240], [90, 130],
    ];

    /** @var array<int, string> */
    private array $pool = [];

    public function __construct(private readonly string $disk = 'public') {}

    /**
     * Render the shared pool once. Everything afterwards is a copy.
     *
     * @return array<int, string> pool paths
     */
    public function buildPool(int $size = 900, ?callable $onProgress = null): array
    {
        $this->ensureGd();

        $this->pool = [];

        for ($i = 0; $i < $size; $i++) {
            $path = "photos/_pool/{$i}.jpg";
            $thumbPath = "photos/_pool/{$i}-thumb.jpg";

            if (! Storage::disk($this->disk)->exists($path)) {
                // No initials on pooled photos: a pool image is shared by many
                // members, so any letters would be wrong for nearly all of them —
                // "YK" on Cindy's profile reads as a bug, not a placeholder.
                $image = $this->renderPlate(self::PHOTO_WIDTH, self::PHOTO_HEIGHT, $i, '');
                $this->writeJpeg($image, $path);
                imagedestroy($image);
            }

            /*
             * Thumbnails are pooled too.
             *
             * Every assigned photo is a copy of a pool image, so its thumbnail
             * is a copy of that pool image's thumbnail. Resampling one per photo
             * instead turns a one-minute stage into half an hour: the decode,
             * resample and re-encode costs ~25ms each, and there are tens of
             * thousands of them.
             */
            if (! Storage::disk($this->disk)->exists($thumbPath)) {
                $this->writeThumbnail(Storage::disk($this->disk)->path($path), $thumbPath);
            }

            $this->pool[] = $path;

            if ($onProgress !== null) {
                $onProgress($i + 1, $size);
            }
        }

        return $this->pool;
    }

    /**
     * Assign a pool image to one member photo.
     *
     * Deterministic on (appUserId, position) so `migrate:fresh --seed` produces
     * byte-identical files and does not churn the disk.
     *
     * @return array{path: string, thumb_path: string, width: int, height: int, bytes: int}
     */
    public function assign(int $appUserId, int $position, ?int $poolIndexOverride = null): array
    {
        if ($this->pool === []) {
            throw new RuntimeException('Call buildPool() before assign().');
        }

        $seed = $poolIndexOverride ?? (int) sprintf('%u', crc32("{$appUserId}:{$position}"));
        $index = $seed % count($this->pool);
        $source = $this->pool[$index];
        $sourceThumb = str_replace('.jpg', '-thumb.jpg', $source);

        $shard = substr(md5("{$appUserId}:{$position}"), 0, 4);
        $path = "photos/{$shard[0]}{$shard[1]}/{$shard[2]}{$shard[3]}/{$appUserId}-{$position}.jpg";
        $thumbPath = "photos/{$shard[0]}{$shard[1]}/{$shard[2]}{$shard[3]}/{$appUserId}-{$position}-thumb.jpg";

        $storage = Storage::disk($this->disk);

        // Both files are plain copies. Nothing is re-encoded per photo, which is
        // what keeps this stage to seconds rather than half an hour.
        if (! $storage->exists($path)) {
            $storage->put($path, $storage->get($source));
        }

        if (! $storage->exists($thumbPath)) {
            $storage->put($thumbPath, $storage->get($sourceThumb));
        }

        return [
            'path' => $path,
            'thumb_path' => $thumbPath,
            'width' => self::PHOTO_WIDTH,
            'height' => self::PHOTO_HEIGHT,
            'bytes' => $storage->size($path),
        ];
    }

    /**
     * A verification selfie: square, hue-shifted, with a gesture badge.
     *
     * Visibly related to the member's profile plate but not identical, so the
     * review screen's comparator has something meaningful to compare.
     */
    public function selfie(int $appUserId, string $gestureCode, string $disk = 'verifications'): array
    {
        $this->ensureGd();

        $seed = (int) sprintf('%u', crc32("selfie:{$appUserId}"));
        $image = $this->renderPlate(self::SELFIE_SIZE, self::SELFIE_SIZE, $seed, $this->initialsFor($seed));

        $this->stampGesture($image, $gestureCode);

        $shard = substr(md5("selfie:{$appUserId}"), 0, 4);
        $path = "selfies/{$shard[0]}{$shard[1]}/{$shard[2]}{$shard[3]}/{$appUserId}.jpg";

        ob_start();
        imagejpeg($image, null, 82);
        Storage::disk($disk)->put($path, (string) ob_get_clean());
        imagedestroy($image);

        return ['disk' => $disk, 'path' => $path];
    }

    // ---- rendering ----

    /**
     * @return \GdImage
     */
    private function renderPlate(int $width, int $height, int $seed, string $initials)
    {
        mt_srand($seed);

        $image = imagecreatetruecolor($width, $height);
        [$hueA, $hueB] = self::PALETTE[$seed % count(self::PALETTE)];

        // Vertical gradient, drawn line by line.
        for ($y = 0; $y < $height; $y++) {
            $t = $y / max(1, $height - 1);
            [$r, $g, $b] = $this->hslToRgb(
                $this->lerp($hueA, $hueB, $t) / 360,
                0.42,
                $this->lerp(0.62, 0.38, $t),
            );

            imagefilledrectangle($image, 0, $y, $width, $y, imagecolorallocate($image, $r, $g, $b));
        }

        // Translucent geometry for texture.
        for ($i = 0, $shapes = mt_rand(3, 6); $i < $shapes; $i++) {
            $tint = imagecolorallocatealpha(
                $image,
                mt_rand(200, 255),
                mt_rand(200, 255),
                mt_rand(200, 255),
                mt_rand(100, 118),
            );

            imagefilledellipse(
                $image,
                mt_rand(0, $width),
                mt_rand(0, $height),
                mt_rand((int) ($width * 0.2), (int) ($width * 0.8)),
                mt_rand((int) ($width * 0.2), (int) ($width * 0.8)),
                $tint,
            );
        }

        // Abstract figure: head plus shoulders, clearly not a photograph.
        $figure = imagecolorallocatealpha($image, 255, 255, 255, 96);
        $cx = (int) ($width / 2);
        $headR = (int) ($width * 0.22);
        $headY = (int) ($height * 0.38);

        imagefilledellipse($image, $cx, $headY, $headR * 2, $headR * 2, $figure);
        imagefilledellipse(
            $image,
            $cx,
            (int) ($height * 0.92),
            (int) ($width * 0.78),
            (int) ($height * 0.58),
            $figure,
        );

        $this->drawInitials($image, $initials, $width, $height);

        mt_srand();

        return $image;
    }

    private function drawInitials($image, string $initials, int $width, int $height): void
    {
        if ($initials === '') {
            return;
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $font = $this->resolveFont();
        $size = (int) ($width * 0.13);
        $y = (int) ($height * 0.44);

        if ($font !== null) {
            $box = imagettfbbox($size, 0, $font, $initials);
            $textWidth = abs($box[4] - $box[0]);

            imagettftext(
                $image,
                $size,
                0,
                (int) (($width - $textWidth) / 2),
                $y,
                $white,
                $font,
                $initials,
            );

            return;
        }

        // No usable TTF: fall back to the bitmap font, scaled up. Ugly but it
        // means the seeder never dies on a machine without fonts.
        $temp = imagecreatetruecolor(40, 20);
        imagefill($temp, 0, 0, imagecolorallocate($temp, 0, 0, 0));
        imagecolortransparent($temp, imagecolorallocate($temp, 0, 0, 0));
        imagestring($temp, 5, 8, 2, $initials, imagecolorallocate($temp, 255, 255, 255));

        $target = (int) ($width * 0.3);
        imagecopyresampled(
            $image,
            $temp,
            (int) (($width - $target) / 2),
            $y - (int) ($target / 4),
            0,
            0,
            $target,
            (int) ($target / 2),
            40,
            20,
        );

        imagedestroy($temp);
    }

    private function stampGesture($image, string $gestureCode): void
    {
        $size = imagesx($image);
        $badgeH = (int) ($size * 0.12);

        $backdrop = imagecolorallocatealpha($image, 0, 0, 0, 70);
        imagefilledrectangle($image, 0, $size - $badgeH, $size, $size, $backdrop);

        $white = imagecolorallocate($image, 255, 255, 255);
        $font = $this->resolveFont();
        $text = "GESTURE {$gestureCode}";

        if ($font !== null) {
            imagettftext($image, (int) ($size * 0.045), 0, (int) ($size * 0.06), $size - (int) ($badgeH * 0.35), $white, $font, $text);
        } else {
            imagestring($image, 4, (int) ($size * 0.06), $size - (int) ($badgeH * 0.7), $text, $white);
        }
    }

    private function writeJpeg($image, string $path): void
    {
        ob_start();
        imagejpeg($image, null, 82);
        Storage::disk($this->disk)->put($path, (string) ob_get_clean());
    }

    private function writeThumbnail(string $sourceAbsolutePath, string $thumbPath): void
    {
        $source = imagecreatefromjpeg($sourceAbsolutePath);
        $thumb = imagecreatetruecolor(self::THUMB_WIDTH, self::THUMB_HEIGHT);

        imagecopyresampled(
            $thumb,
            $source,
            0, 0, 0, 0,
            self::THUMB_WIDTH,
            self::THUMB_HEIGHT,
            imagesx($source),
            imagesy($source),
        );

        ob_start();
        imagejpeg($thumb, null, 78);
        Storage::disk($this->disk)->put($thumbPath, (string) ob_get_clean());

        imagedestroy($thumb);
        imagedestroy($source);
    }

    /**
     * Resolve a TTF, trying a bundled font, then a system one. Returning null
     * is fine — drawInitials() has a bitmap fallback.
     */
    private function resolveFont(): ?string
    {
        static $resolved = false;
        static $font = null;

        if ($resolved) {
            return $font;
        }

        $resolved = true;

        foreach ([
            resource_path('fonts/Inter-SemiBold.ttf'),
            'C:\Windows\Fonts\segoeuib.ttf',
            'C:\Windows\Fonts\segoeui.ttf',
            'C:\Windows\Fonts\arialbd.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $font = $candidate;
            }
        }

        return null;
    }

    private function initialsFor(int $seed): string
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        return $letters[$seed % 26].$letters[intdiv($seed, 26) % 26];
    }

    private function lerp(float $from, float $to, float $t): float
    {
        return $from + ($to - $from) * $t;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function hslToRgb(float $h, float $s, float $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h * 6, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match ((int) floor($h * 6) % 6) {
            0 => [$c, $x, 0.0],
            1 => [$x, $c, 0.0],
            2 => [0.0, $c, $x],
            3 => [0.0, $x, $c],
            4 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return [
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        ];
    }

    private function ensureGd(): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException(
                'ext-gd is required to generate demo photos. Set VEYRA_SEED_PHOTOS=none to skip them.',
            );
        }
    }
}
