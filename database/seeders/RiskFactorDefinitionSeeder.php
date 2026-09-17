<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\RiskFactorDefinition;
use Illuminate\Database\Seeder;

/**
 * The risk factor catalogue.
 *
 * Weights are database rows so trust & safety can retune them without a deploy.
 * Mitigating factors carry negative points and read as credits in the UI — a
 * score that can only go up is a score that eventually flags everyone.
 */
class RiskFactorDefinitionSeeder extends Seeder
{
    /** @return array<int, array{0: string, 1: string, 2: int, 3: string}> */
    public static function definitions(): array
    {
        return [
            // key, label, points, category
            ['prior_permanent_ban_device', 'Device linked to a permanently banned account', 45, 'identity'],
            ['device_shared_with_banned_account', 'Device shared with a banned account', 40, 'identity'],
            ['duplicate_face_detected', 'Same face on another account', 35, 'identity'],
            ['age_estimate_conflict', 'Estimated age conflicts with stated age', 32, 'identity'],
            ['new_account_24h', 'New account, under 24 hours', 30, 'tenure'],
            ['multiple_reports_7d', 'Multiple reports in the last 7 days', 28, 'reports'],
            ['off_platform_link_first_message', 'Off-platform link in a first message', 25, 'behaviour'],
            ['blocked_by_many', 'Blocked by an unusual number of members', 22, 'behaviour'],
            ['duplicate_photo_hash', 'Photo reused from another account', 20, 'identity'],
            ['prior_enforcement', 'Previous enforcement on this account', 20, 'history'],
            ['report_by_high_credibility_reporter', 'Reported by a consistently accurate reporter', 18, 'reports'],
            ['nudity_label_high', 'Photo flagged for nudity', 18, 'content'],
            ['swipe_velocity_outlier', 'Swipe volume far above normal', 16, 'behaviour'],
            ['contact_info_in_bio', 'Contact details in the profile bio', 15, 'behaviour'],
            ['message_velocity_outlier', 'Message volume far above normal', 14, 'behaviour'],
            ['disposable_email_domain', 'Disposable email domain', 14, 'identity'],
            ['new_account_7d', 'New account, under 7 days', 12, 'tenure'],
            ['no_photos', 'No photos uploaded', 10, 'profile'],
            ['unverified_after_30d', 'Still unverified after 30 days', 8, 'profile'],
            ['profile_incomplete', 'Profile substantially incomplete', 6, 'profile'],

            // Mitigating
            ['photo_verified', 'Photo verified', -15, 'mitigating'],
            ['account_age_over_180d', 'Account older than 180 days', -12, 'mitigating'],
            ['high_reply_rate', 'Consistently gets replies', -8, 'mitigating'],
            ['premium_subscriber', 'Paying subscriber', -5, 'mitigating'],
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as [$key, $label, $points, $category]) {
            RiskFactorDefinition::query()->updateOrCreate(
                ['key' => $key],
                ['label' => $label, 'points' => $points, 'category' => $category, 'is_active' => true],
            );
        }

        $this->command?->info('Seeded '.count(self::definitions()).' risk factor definitions.');
    }
}
