<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Operator-editable settings, mirroring config/veyra.php.
 *
 * Idempotent on `key`: re-running adds newly introduced settings without
 * clobbering values an operator has already tuned.
 */
class SettingSeeder extends Seeder
{
    /** @return array<int, array<string, mixed>> */
    public static function settings(): array
    {
        return [
            /*
             * ---- branding ----
             *
             * Everything a buyer changes to make the product theirs, edited from
             * Settings -> Branding rather than the generic settings list: logos
             * need an upload field and a colour needs a picker, and a live
             * preview is what stops somebody shipping an unreadable palette.
             */
            ['key' => 'brand.name', 'value' => 'Veyra', 'type' => 'text', 'group' => 'branding', 'label' => 'Product name', 'is_public' => true],
            ['key' => 'brand.tagline', 'value' => 'Trust & Safety Console', 'type' => 'text', 'group' => 'branding', 'label' => 'Admin tagline', 'description' => 'Shown under the name in the admin sidebar.'],
            ['key' => 'brand.primary_color', 'value' => '#c2265a', 'type' => 'text', 'group' => 'branding', 'label' => 'Brand colour', 'is_public' => true],
            ['key' => 'brand.theme_mode', 'value' => 'system', 'type' => 'text', 'group' => 'branding', 'label' => 'Default theme', 'description' => 'light, dark or system. Each person can still switch their own.'],
            ['key' => 'brand.admin_logo', 'value' => '', 'type' => 'image', 'group' => 'branding', 'label' => 'Admin logo'],
            ['key' => 'brand.logo', 'value' => '', 'type' => 'image', 'group' => 'branding', 'label' => 'Website logo', 'is_public' => true],
            ['key' => 'brand.favicon', 'value' => '', 'type' => 'image', 'group' => 'branding', 'label' => 'Favicon', 'is_public' => true],
            ['key' => 'brand.login_image', 'value' => '', 'type' => 'image', 'group' => 'branding', 'label' => 'Sign-in page image'],
            ['key' => 'brand.support_email', 'value' => 'support@veyra.test', 'type' => 'text', 'group' => 'branding', 'label' => 'Support email', 'is_public' => true],

            ['key' => 'business.name', 'value' => 'Veyra Ltd', 'type' => 'text', 'group' => 'branding', 'label' => 'Company name', 'is_public' => true],
            ['key' => 'business.email', 'value' => 'hello@veyra.test', 'type' => 'text', 'group' => 'branding', 'label' => 'Company email', 'is_public' => true],
            ['key' => 'business.phone', 'value' => '', 'type' => 'text', 'group' => 'branding', 'label' => 'Company phone', 'is_public' => true],
            ['key' => 'business.address', 'value' => '', 'type' => 'textarea', 'group' => 'branding', 'label' => 'Company address', 'is_public' => true],

            ['key' => 'website.enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'branding', 'label' => 'Public website', 'description' => 'When off, the home page sends visitors straight to sign in.'],
            ['key' => 'website.hero_title', 'value' => 'Meet people who are exactly who they say they are.', 'type' => 'text', 'group' => 'branding', 'label' => 'Home page headline', 'is_public' => true],
            ['key' => 'website.hero_subtitle', 'value' => 'Every profile photo-verified by a real person. Real conversations, fewer dead ends, and a safety team that actually answers.', 'type' => 'textarea', 'group' => 'branding', 'label' => 'Home page subheading', 'is_public' => true],
            ['key' => 'website.meta_description', 'value' => 'A dating app where every profile is verified.', 'type' => 'text', 'group' => 'branding', 'label' => 'Search engine description', 'is_public' => true],
            ['key' => 'website.footer_text', 'value' => 'Made for people who would rather meet than scroll.', 'type' => 'text', 'group' => 'branding', 'label' => 'Footer text', 'is_public' => true],
            ['key' => 'website.currency_symbol', 'value' => '£', 'type' => 'text', 'group' => 'branding', 'label' => 'Currency symbol', 'is_public' => true],
            ['key' => 'website.plus_price', 'value' => '12.99', 'type' => 'text', 'group' => 'branding', 'label' => 'Plus price / month', 'is_public' => true],
            ['key' => 'website.gold_price', 'value' => '24.99', 'type' => 'text', 'group' => 'branding', 'label' => 'Gold price / month', 'is_public' => true],

            ['key' => 'app.ios_url', 'value' => '', 'type' => 'text', 'group' => 'branding', 'label' => 'App Store link', 'is_public' => true],
            ['key' => 'app.android_url', 'value' => '', 'type' => 'text', 'group' => 'branding', 'label' => 'Google Play link', 'is_public' => true],

            ['key' => 'social.instagram', 'value' => '', 'type' => 'text', 'group' => 'branding', 'label' => 'Instagram', 'is_public' => true],
            ['key' => 'social.facebook', 'value' => '', 'type' => 'text', 'group' => 'branding', 'label' => 'Facebook', 'is_public' => true],
            ['key' => 'social.x', 'value' => '', 'type' => 'text', 'group' => 'branding', 'label' => 'X / Twitter', 'is_public' => true],
            ['key' => 'social.tiktok', 'value' => '', 'type' => 'text', 'group' => 'branding', 'label' => 'TikTok', 'is_public' => true],
            ['key' => 'social.youtube', 'value' => '', 'type' => 'text', 'group' => 'branding', 'label' => 'YouTube', 'is_public' => true],
            ['key' => 'social.linkedin', 'value' => '', 'type' => 'text', 'group' => 'branding', 'label' => 'LinkedIn', 'is_public' => true],

            // ---- general ----
            ['key' => 'general.min_age', 'value' => '18', 'type' => 'number', 'group' => 'general', 'label' => 'Minimum age', 'description' => 'Accounts below this age are removed on discovery.', 'is_public' => true],
            ['key' => 'general.maintenance_mode', 'value' => '0', 'type' => 'boolean', 'group' => 'general', 'label' => 'Maintenance mode', 'is_public' => true],

            // ---- moderation ----
            ['key' => 'sla.case_critical_hours', 'value' => '1', 'type' => 'number', 'group' => 'moderation', 'label' => 'Critical case SLA (hours)'],
            ['key' => 'sla.case_high_hours', 'value' => '8', 'type' => 'number', 'group' => 'moderation', 'label' => 'High case SLA (hours)'],
            ['key' => 'sla.case_medium_hours', 'value' => '24', 'type' => 'number', 'group' => 'moderation', 'label' => 'Medium case SLA (hours)'],
            ['key' => 'sla.case_low_hours', 'value' => '72', 'type' => 'number', 'group' => 'moderation', 'label' => 'Low case SLA (hours)'],
            ['key' => 'sla.appeal_hours', 'value' => '72', 'type' => 'number', 'group' => 'moderation', 'label' => 'Appeal SLA (hours)'],
            ['key' => 'moderation.auto_claim_on_open', 'value' => '1', 'type' => 'boolean', 'group' => 'moderation', 'label' => 'Claim a case when it is opened', 'description' => 'Prevents two moderators acting on the same case.'],
            ['key' => 'moderation.claim_release_minutes', 'value' => '15', 'type' => 'number', 'group' => 'moderation', 'label' => 'Release an idle claim after (minutes)'],
            ['key' => 'moderation.require_note_on_ban', 'value' => '1', 'type' => 'boolean', 'group' => 'moderation', 'label' => 'Require an internal note on every ban'],

            // ---- risk ----
            ['key' => 'risk.auto_queue_at', 'value' => '50', 'type' => 'number', 'group' => 'risk', 'label' => 'Queue for review at score'],
            ['key' => 'risk.auto_limit_at', 'value' => '75', 'type' => 'number', 'group' => 'risk', 'label' => 'Auto feature-limit at score'],
            ['key' => 'risk.recalculate_after_hours', 'value' => '6', 'type' => 'number', 'group' => 'risk', 'label' => 'Recalculate after (hours)'],

            // ---- verification ----
            ['key' => 'verification.sla_hours', 'value' => '24', 'type' => 'number', 'group' => 'verification', 'label' => 'Verification SLA (hours)'],
            ['key' => 'verification.restricted_sla_hours', 'value' => '4', 'type' => 'number', 'group' => 'verification', 'label' => 'Restricted queue SLA (hours)'],
            ['key' => 'verification.max_attempts', 'value' => '3', 'type' => 'number', 'group' => 'verification', 'label' => 'Maximum attempts'],
            ['key' => 'verification.approve_threshold', 'value' => '0.80', 'type' => 'number', 'group' => 'verification', 'label' => 'Face match auto-approve threshold'],
            ['key' => 'verification.required_for_discovery', 'value' => '0', 'type' => 'boolean', 'group' => 'verification', 'label' => 'Require verification to appear in discovery', 'is_public' => true],

            // ---- enforcement ----
            ['key' => 'enforcement.shadow_ban_review_hours', 'value' => '168', 'type' => 'number', 'group' => 'enforcement', 'label' => 'Shadow ban review due after (hours)', 'description' => 'A shadow ban must always carry a review date. It is invisible to the member, so nothing else will prompt a revisit.'],
            ['key' => 'enforcement.shadow_ban_max_hours', 'value' => '720', 'type' => 'number', 'group' => 'enforcement', 'label' => 'Shadow ban maximum duration (hours)'],
            ['key' => 'enforcement.appeal_window_days', 'value' => '30', 'type' => 'number', 'group' => 'enforcement', 'label' => 'Appeal window (days)', 'is_public' => true],
            ['key' => 'enforcement.notify_user_default', 'value' => '1', 'type' => 'boolean', 'group' => 'enforcement', 'label' => 'Notify the member by default'],

            // ---- matching ----
            ['key' => 'matching.daily_like_limit_free', 'value' => '100', 'type' => 'number', 'group' => 'matching', 'label' => 'Daily likes (free)', 'is_public' => true],
            ['key' => 'matching.max_distance_km', 'value' => '160', 'type' => 'number', 'group' => 'matching', 'label' => 'Maximum discovery distance (km)', 'is_public' => true],
            ['key' => 'matching.max_photos', 'value' => '9', 'type' => 'number', 'group' => 'matching', 'label' => 'Maximum photos per profile', 'is_public' => true],
            ['key' => 'matching.unmatch_after_days', 'value' => '0', 'type' => 'number', 'group' => 'matching', 'label' => 'Expire silent matches after (days)', 'description' => '0 disables expiry.'],

            // ---- api ----
            ['key' => 'api.rate_limit_default', 'value' => '90', 'type' => 'number', 'group' => 'api', 'label' => 'Default requests per minute'],
            ['key' => 'api.rate_limit_swipe', 'value' => '120', 'type' => 'number', 'group' => 'api', 'label' => 'Swipes per minute'],
            ['key' => 'api.rate_limit_message', 'value' => '30', 'type' => 'number', 'group' => 'api', 'label' => 'Messages per minute'],
            ['key' => 'api.new_account_throttle', 'value' => '1', 'type' => 'boolean', 'group' => 'api', 'label' => 'Halve limits for accounts under 24h', 'description' => 'Cheap anti-spam that also feeds the velocity risk factors.'],
            ['key' => 'api.min_supported_version', 'value' => '2.4.0', 'type' => 'text', 'group' => 'api', 'label' => 'Minimum supported app version', 'is_public' => true],

            // ---- privacy ----
            ['key' => 'privacy.message_reveal_minutes', 'value' => '15', 'type' => 'number', 'group' => 'privacy', 'label' => 'Message reveal lasts (minutes)'],
            ['key' => 'privacy.message_context_window', 'value' => '10', 'type' => 'number', 'group' => 'privacy', 'label' => 'Messages of context either side of an anchor'],
            ['key' => 'privacy.require_justification', 'value' => '1', 'type' => 'boolean', 'group' => 'privacy', 'label' => 'Require written justification to reveal messages'],
        ];
    }

    public function run(): void
    {
        foreach (self::settings() as $index => $setting) {
            Setting::query()->updateOrCreate(
                ['key' => $setting['key']],
                [
                    // `value` is intentionally NOT overwritten on re-run — an
                    // operator's tuning must survive a reseed of the catalogue.
                    'value' => Setting::query()->where('key', $setting['key'])->value('value') ?? $setting['value'],
                    'type' => $setting['type'],
                    'group' => $setting['group'],
                    'label' => $setting['label'] ?? null,
                    'description' => $setting['description'] ?? null,
                    'is_public' => $setting['is_public'] ?? false,
                    'sort_order' => $index,
                ],
            );
        }

        $this->command?->info('Seeded '.count(self::settings()).' settings.');
    }
}
