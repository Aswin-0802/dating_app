<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\AccountStatus;
use App\Enums\VerificationStatus;
use App\Models\AppUser;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a campaign goes to.
 *
 * One definition, used by the wizard's "this reaches N people" count and by
 * the sender — if they were written twice, the number an approver signed off
 * would not be the number that received it.
 */
final class CampaignAudience
{
    /** @var array<string, string> */
    public const AUDIENCES = [
        'all' => 'Everyone active',
        'inactive_14' => 'Not active in 14 days',
        'unverified' => 'Not photo verified',
        'incomplete' => 'Profile under half done',
        'premium' => 'On a paid plan',
        'free' => 'On the free tier',
    ];

    public static function query(string $audience): Builder
    {
        // Restricted and deactivated accounts are never messaged: a suspended
        // member being nudged to "come back and swipe" is not a good look.
        $query = AppUser::query()->where('account_status', AccountStatus::Active->value);

        return match ($audience) {
            'inactive_14' => $query->where('last_active_at', '<', now()->subDays(14)),
            'unverified' => $query->where('verification_status', '!=', VerificationStatus::Approved->value),
            'incomplete' => $query->where('profile_completion', '<', 50),
            'premium' => $query->where('is_premium', true),
            'free' => $query->where('is_premium', false),
            default => $query,
        };
    }

    public static function label(string $audience): string
    {
        return self::AUDIENCES[$audience] ?? self::AUDIENCES['all'];
    }
}
