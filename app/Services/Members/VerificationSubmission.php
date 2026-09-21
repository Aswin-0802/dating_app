<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\AppUser;
use App\Models\Verification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * A member submitting a selfie for photo verification.
 *
 * The gesture code is issued by the server and must appear in the capture: it
 * is what stops somebody uploading a photograph of a photograph. Shared by the
 * website and the mobile API, and lands in the same admin review queue.
 */
final class VerificationSubmission
{
    public static function newGestureCode(): string
    {
        return strtoupper(Str::random(2).random_int(10, 99));
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
            'gesture_code' => strtoupper($gestureCode),
            'submitted_at' => now(),
            'sla_due_at' => now()->addHours((int) platform_setting('verification.sla_hours', 24)),
        ]);

        $member->forceFill(['verification_status' => 'pending'])->save();

        return $verification;
    }

    private function maxAttempts(): int
    {
        return (int) config('platform.verification.max_attempts', 3);
    }
}
