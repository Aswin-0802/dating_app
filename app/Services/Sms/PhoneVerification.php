<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\AppUser;
use App\Models\PhoneVerification as VerificationRecord;
use App\Support\Branding;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Proving a phone number belongs to the member holding it.
 *
 * Six digits, ten minutes, five guesses, one code at a time. The numbers are
 * deliberately tight: an SMS code is the weakest factor in the product, and
 * the cost of a wrong guess being cheap is somebody else's account.
 */
final class PhoneVerification
{
    private const CODE_TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    /** How long before another code can be asked for. */
    private const RESEND_SECONDS = 60;

    public function __construct(private readonly SmsSender $sms) {}

    public function available(): bool
    {
        return $this->sms->isConfigured();
    }

    /**
     * Text a code to a number.
     *
     * @throws ValidationException when it is too soon, the number is taken, or
     *                             the text could not be sent
     */
    public function start(AppUser $member, string $phone): VerificationRecord
    {
        $phone = trim($phone);

        if (! $this->available()) {
            throw ValidationException::withMessages([
                'phone' => 'Phone verification is not available at the moment.',
            ]);
        }

        // Somebody else's verified number cannot be claimed: a phone is one of
        // the few things that make an account recoverable.
        $taken = AppUser::query()
            ->where('phone', $phone)
            ->whereNotNull('phone_verified_at')
            ->whereKeyNot($member->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'phone' => 'That number is already verified on another account.',
            ]);
        }

        $recent = VerificationRecord::query()
            ->where('app_user_id', $member->id)
            ->whereNull('verified_at')
            ->where('created_at', '>', now()->subSeconds(self::RESEND_SECONDS))
            ->first();

        if ($recent !== null) {
            $wait = self::RESEND_SECONDS - (int) $recent->created_at->diffInSeconds(now());

            throw ValidationException::withMessages([
                'phone' => "Wait {$wait} seconds before asking for another code.",
            ]);
        }

        // Only one code is live at a time, so an old text cannot be used.
        VerificationRecord::query()->where('app_user_id', $member->id)->whereNull('verified_at')->delete();

        $code = (string) random_int(100000, 999999);

        $record = VerificationRecord::query()->create([
            'app_user_id' => $member->id,
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        $name = Branding::name();
        $sent = $this->sms->send($phone, "{$code} is your {$name} verification code. It expires in ".self::CODE_TTL_MINUTES.' minutes.', $member);

        if (! $sent) {
            $record->delete();

            throw ValidationException::withMessages([
                'phone' => 'We could not text that number. Check it and try again.',
            ]);
        }

        return $record;
    }

    /**
     * Check a code and, if it matches, mark the number verified.
     *
     * @throws ValidationException
     */
    public function confirm(AppUser $member, string $code): void
    {
        $record = VerificationRecord::query()
            ->where('app_user_id', $member->id)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if ($record === null || $record->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => 'That code has expired. Ask for a new one.',
            ]);
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages([
                'code' => 'Too many wrong tries. Ask for a new code.',
            ]);
        }

        if (! Hash::check(trim($code), $record->code_hash)) {
            $record->increment('attempts');

            throw ValidationException::withMessages([
                'code' => 'That code is not right.',
            ]);
        }

        $record->forceFill(['verified_at' => now()])->save();

        $member->forceFill([
            'phone' => $record->phone,
            'phone_verified_at' => now(),
        ])->save();
    }
}
