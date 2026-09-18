<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Enums\AccountStatus;
use App\Models\AppUser;

/**
 * How complete a profile is, and the promotion out of `pending` once there is
 * enough there to be worth showing to anybody.
 */
final class ProfileCompletion
{
    /** @return array<string, bool> checklist item => done */
    public function checklist(AppUser $member): array
    {
        $profile = $member->profile;

        return [
            'Add a bio' => filled($profile?->bio),
            'Add your job' => filled($profile?->job_title),
            'Add your education' => filled($profile?->education),
            'Add your height' => $profile?->height_cm !== null,
            'Say what you are looking for' => ($profile?->relationship_goal ?? 'unspecified') !== 'unspecified',
            'Upload a photo' => $member->photos()->count() > 0,
            'Pick three interests' => $member->interests()->count() >= 3,
            'Set your city' => filled($member->city_id),
        ];
    }

    public function refresh(AppUser $member): int
    {
        $items = $this->checklist($member);
        $completion = (int) round(count(array_filter($items)) / count($items) * 100);

        $attributes = ['profile_completion' => $completion];

        if ($completion >= 50 && $member->account_status === AccountStatus::Pending) {
            $attributes['account_status'] = AccountStatus::Active;
            $attributes['profile_completed_at'] = $member->profile_completed_at ?? now();
        }

        $member->forceFill($attributes)->save();

        return $completion;
    }
}
