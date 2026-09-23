<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\AppUser;
use App\Models\Verification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A member submitting a selfie for photo verification.
 *
 * The gesture code is issued by the server and must appear in the capture: it
 * is what stops somebody uploading a photograph of a photograph. Shared by the
 * website and the mobile API, and lands in the same admin review queue.
 *
 * That property only holds if the server remembers which code it issued. It
 * used to hand one out and then store whatever the client sent back, so an
 * attacker could choose their own code and prepare a matching image at leisure;
 * the reviewer would compare the photo against the attacker's own value and see
 * a match. The code is now held against the member, for one submission only.
 */
final class VerificationSubmission
{
    /** Long enough to take a photo, short enough that a leaked code is stale. */
    public const GESTURE_TTL_MINUTES = 10;

    public static function newGestureCode(): string
    {
        return strtoupper(Str::random(2).random_int(10, 99));
    }

    /**
     * Issue a code and remember it.
     *
     * One outstanding code per member: asking again replaces the last one, so
     * collecting a pile of valid codes is not possible.
     */
    public function issueGestureCode(AppUser $member): string
    {
        $code = self::newGestureCode();

        Cache::put($this->gestureKey($member), $code, now()->addMinutes(self::GESTURE_TTL_MINUTES));

        return $code;
    }

    public function outstandingGestureCode(AppUser $member): ?string
    {
        return Cache::get($this->gestureKey($member));
    }

    public function attemptsLeft(AppUser $member): int
    {
        return max(0, $this->maxAttempts() - Verification::query()->where('app_user_id', $member->id)->count());
    }

    /** Null when the member has used every attempt. */
    public function submit(AppUser $member, UploadedFile $selfie, string $gestureCode): ?Verification
    {
        $used = Verification::query()->where('app_user_id', $member->id)->count();

        if ($used >= $this->maxAttempts()) {
            return null;
        }

        $expected = $this->outstandingGestureCode($member);

        if ($expected === null || ! hash_equals($expected, strtoupper(trim($gestureCode)))) {
            throw ValidationException::withMessages([
                'gesture_code' => 'That code has expired or does not match the one we issued. Ask for a new code and take the photo again.',
            ]);
        }

        // Stored on the private disk: a selfie is identity data and is only
        // ever served through the permission-checked admin route.
        $path = $selfie->store('selfies', 'verifications');

        $verification = Verification::query()->create([
            'uuid' => (string) Str::uuid(),
            'app_user_id' => $member->id,
            'attempt_no' => $used + 1,
            'type' => 'selfie',
            'status' => 'pending',
            'queue' => 'standard',
            'selfie_disk' => 'verifications',
            'selfie_path' => $path,
            'gesture_code' => $expected,
            'submitted_at' => now(),
            'sla_due_at' => now()->addHours((int) platform_setting('verification.sla_hours', 24)),
        ]);

        // Single use: the code that was photographed cannot be reused for a
        // second, differently-sourced image.
        Cache::forget($this->gestureKey($member));

        $member->forceFill(['verification_status' => 'pending'])->save();

        return $verification;
    }

    private function gestureKey(AppUser $member): string
    {
        return "verification.gesture.{$member->id}";
    }

    private function maxAttempts(): int
    {
        return (int) config('platform.verification.max_attempts', 3);
    }
}
