<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\AppUser;
use App\Models\Photo;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores a profile photo a member uploads.
 *
 * Every upload is decoded and re-encoded rather than stored as sent. That is
 * the privacy point: phone photos carry EXIF, including the GPS position where
 * they were taken — often the member's home — and a profile photo is served to
 * strangers. Re-encoding drops all of it. It also normalises orientation and
 * size, and gives the phash the same input on every path.
 */
final class MemberPhotoStore
{
    /** Only the fallback for a fresh install; the operator's setting wins. */
    public const DEFAULT_MAX_PHOTOS = 6;

    private const FULL_WIDTH = 1200;

    private const THUMB_WIDTH = 480;

    /**
     * How many photos a member may have.
     *
     * Read from Settings → Matching, which is the number the API advertises
     * and the number the settings screen shows. It used to be a constant here
     * while /api/v1/config reported the setting, so a client that honoured the
     * advertised limit of 9 failed on the seventh upload with no way to know
     * why. One source of truth, and it is the operator's.
     */
    public static function maxPhotos(): int
    {
        return max(1, (int) platform_setting('matching.max_photos', self::DEFAULT_MAX_PHOTOS));
    }

    public function store(AppUser $member, UploadedFile $file): Photo
    {
        $limit = self::maxPhotos();

        if ($member->photos()->count() >= $limit) {
            throw new RuntimeException('You can have up to '.$limit.' photos.');
        }

        $image = $this->decode($file);
        $uuid = (string) Str::uuid();
        $dir = 'photos/'.substr($uuid, 0, 2);

        $full = $this->resize($image, self::FULL_WIDTH);
        $thumb = $this->resize($image, self::THUMB_WIDTH);

        $path = "{$dir}/{$uuid}.jpg";
        $thumbPath = "{$dir}/{$uuid}_thumb.jpg";

        Storage::disk('public')->put($path, $this->jpeg($full, 85));
        Storage::disk('public')->put($thumbPath, $this->jpeg($thumb, 80));

        return DB::transaction(function () use ($member, $uuid, $path, $thumbPath, $full): Photo {
            $isFirst = ! $member->photos()->exists();

            return Photo::query()->create([
                'uuid' => $uuid,
                'app_user_id' => $member->id,
                'disk' => 'public',
                'path' => $path,
                'thumb_path' => $thumbPath,
                'position' => (int) $member->photos()->max('position') + 1,
                'is_primary' => $isFirst,
                'width' => imagesx($full),
                'height' => imagesy($full),
                'bytes' => Storage::disk('public')->size($path),
                'phash' => $this->averageHash($full),
                // New photos wait for moderation, exactly as on the mobile API.
                'moderation_status' => 'pending',
            ]);
        });
    }

    public function delete(Photo $photo): void
    {
        $member = $photo->appUser;
        $wasPrimary = $photo->is_primary;

        // A soft delete, and the files stay: the photo disappears from the
        // profile at once, but a photo that is evidence in an open report must
        // not vanish because the reported member deleted it. Purging old
        // soft-deleted media is a retention job, not a member action.
        $photo->delete();

        // Somebody always has to be the main photo if any are left.
        if ($wasPrimary && $member !== null) {
            $member->photos()->orderBy('position')->first()?->update(['is_primary' => true]);
        }
    }

    public function makePrimary(Photo $photo): void
    {
        DB::transaction(function () use ($photo): void {
            Photo::query()->where('app_user_id', $photo->app_user_id)->update(['is_primary' => false]);
            $photo->update(['is_primary' => true]);
        });
    }

    private function decode(UploadedFile $file): GdImage
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if (! $image instanceof GdImage) {
            throw new RuntimeException('That file could not be read as an image.');
        }

        // Honour the camera's rotation before the EXIF that records it is
        // discarded, or portrait phone photos come out sideways.
        if (function_exists('exif_read_data') && in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            $orientation = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);

            $image = match ($orientation) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => $image,
            };
        }

        return $image;
    }

    private function resize(GdImage $image, int $maxWidth): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= $maxWidth) {
            $copy = imagecreatetruecolor($width, $height);
            imagecopy($copy, $image, 0, 0, 0, 0, $width, $height);

            return $copy;
        }

        $newHeight = (int) round($height * $maxWidth / $width);
        $resized = imagecreatetruecolor($maxWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);

        return $resized;
    }

    private function jpeg(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    /** A 64-bit average hash, hex-encoded, for spotting the same image on two accounts. */
    private function averageHash(GdImage $image): string
    {
        $small = imagecreatetruecolor(8, 8);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 8, 8, imagesx($image), imagesy($image));

        $values = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $values[] = (($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114;
            }
        }

        $mean = array_sum($values) / 64;
        $bits = implode('', array_map(fn (float $v): string => $v >= $mean ? '1' : '0', $values));

        return str_pad(implode('', array_map(fn (string $chunk): string => dechex(bindec($chunk)), str_split($bits, 4))), 16, '0');
    }
}
