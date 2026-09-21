<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ReasonCode;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Notification templates.
 *
 * The enforcement notices are generated from the ReasonCode registry, so the
 * statement a member receives and the reason a moderator selects are the same
 * source of truth. Writing them out by hand would let the two drift, and a
 * statement of reasons that does not match the recorded reason is worse than
 * none at all.
 */
class NotificationTemplateSeeder extends Seeder
{
    /**
     * Renewal warnings and the notice that a plan has ended.
     *
     * Transactional: a member is told before money stops working, so the
     * wording can be edited but the template cannot be deleted.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function billingTemplates(): array
    {
        return [
            [
                'key' => 'billing.renewal_push',
                'name' => 'Plan ending soon (push)',
                'category' => 'billing',
                'channel' => 'push',
                'subject' => '{{ plan_name }} ends in {{ days_left }} days',
                'body' => 'Renew before {{ end_date }} to keep {{ plan_name }}.',
                'placeholders' => ['first_name', 'plan_name', 'days_left', 'end_date'],
                'is_transactional' => true,
            ],
            [
                'key' => 'billing.renewal_email',
                'name' => 'Plan ending soon (email)',
                'category' => 'billing',
                'channel' => 'email',
                'subject' => 'Your {{ plan_name }} ends in {{ days_left }} days',
                'body' => "Hi {{ first_name }},\n\nYour {{ plan_name }} plan ends on {{ end_date }}.\n\nRenew before then to keep unlimited likes and everything else your plan includes. Nothing else about your account changes, and your matches and messages stay exactly as they are.",
                'placeholders' => ['first_name', 'plan_name', 'days_left', 'end_date'],
                'is_transactional' => true,
            ],
            [
                'key' => 'billing.expired',
                'name' => 'Plan ended',
                'category' => 'billing',
                'channel' => 'email',
                'subject' => 'Your {{ plan_name }} has ended',
                'body' => "Hi {{ first_name }},\n\nYour {{ plan_name }} plan ended today, so your account is back on the free tier.\n\nYour profile, matches and messages are all still here. You can start a plan again whenever you like.",
                'placeholders' => ['first_name', 'plan_name'],
                'is_transactional' => true,
            ],
            [
                'key' => 'billing.plan_started',
                'name' => 'Plan started',
                'category' => 'billing',
                'channel' => 'email',
                'subject' => 'Your {{ plan_name }} is active',
                'body' => "Hi {{ first_name }},\n\n{{ plan_name }} is now active on your account{{ until_clause }}.\n\nEverything it unlocks is available straight away.",
                'placeholders' => ['first_name', 'plan_name', 'until_clause'],
                'is_transactional' => true,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function engagementTemplates(): array
    {
        return [
            [
                'key' => 'match.new',
                'name' => 'New match',
                'category' => 'engagement',
                'subject' => 'You matched with {{ name }}',
                'body' => 'You and {{ name }} liked each other. Say hello.',
                'placeholders' => ['name'],
            ],
            [
                'key' => 'message.new',
                'name' => 'New message',
                'category' => 'engagement',
                'subject' => '{{ name }} sent you a message',
                'body' => '{{ name }}: {{ preview }}',
                'placeholders' => ['name', 'preview'],
            ],
            [
                'key' => 'match.silent_nudge',
                'name' => 'Silent match nudge',
                'category' => 'engagement',
                'subject' => 'You matched with {{ name }} {{ elapsed }} ago',
                'body' => 'Neither of you has said anything yet. Someone has to go first.',
                'placeholders' => ['name', 'elapsed'],
            ],
            [
                'key' => 'likes.waiting',
                'name' => 'Likes waiting',
                'category' => 'engagement',
                'subject' => '{{ count }} people liked you',
                'body' => 'Open the app to see who.',
                'placeholders' => ['count'],
            ],
            [
                'key' => 'profile.incomplete',
                'name' => 'Incomplete profile',
                'category' => 'onboarding',
                'subject' => 'Your profile is {{ completion }}% complete',
                'body' => 'Profiles with photos and a bio get far more matches. It takes two minutes.',
                'placeholders' => ['completion'],
            ],
            [
                'key' => 'verification.prompt',
                'name' => 'Verification prompt',
                'category' => 'onboarding',
                'subject' => 'Get verified',
                'body' => 'Verified profiles get noticeably more matches, and it takes about a minute.',
                'placeholders' => [],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function transactionalTemplates(): array
    {
        return [
            [
                'key' => 'verification.approved',
                'name' => 'Verification approved',
                'category' => 'verification',
                'subject' => 'You are verified',
                'body' => 'Your photo verification was approved. Your profile now carries a verified badge.',
                'placeholders' => [],
            ],
            [
                'key' => 'verification.rejected',
                'name' => 'Verification rejected',
                'category' => 'verification',
                'subject' => 'We could not verify your photo',
                'body' => "We could not verify your submission: {{ reason }}.\n\nYou have {{ attempts_remaining }} attempts remaining.",
                'placeholders' => ['reason', 'attempts_remaining'],
            ],
            [
                'key' => 'appeal.received',
                'name' => 'Appeal received',
                'category' => 'appeals',
                'subject' => 'We received your appeal',
                'body' => 'A different member of our team will review it. We aim to respond within {{ sla_hours }} hours.',
                'placeholders' => ['sla_hours'],
            ],
            [
                'key' => 'appeal.decided',
                'name' => 'Appeal decided',
                'category' => 'appeals',
                'subject' => 'Your appeal has been reviewed',
                'body' => "{{ outcome }}\n\n{{ reasoning }}",
                'placeholders' => ['outcome', 'reasoning'],
            ],
        ];
    }

    public function run(): void
    {
        $sort = 0;

        foreach ([...self::engagementTemplates(), ...self::transactionalTemplates(), ...self::billingTemplates()] as $template) {
            $isTransactional = in_array(
                $template['category'],
                ['verification', 'appeals', 'enforcement'],
                true,
            );

            NotificationTemplate::query()->updateOrCreate(
                ['key' => $template['key']],
                [
                    'name' => $template['name'],
                    'audience' => 'member',
                    'channel' => $template['channel'] ?? 'push',
                    'category' => $template['category'],
                    'subject' => $template['subject'],
                    'body' => $template['body'],
                    'placeholders' => $template['placeholders'],
                    'is_transactional' => $template['is_transactional'] ?? $isTransactional,
                    'is_active' => true,
                ],
            );

            $sort++;
        }

        /*
         * One enforcement template per reason code, generated rather than typed.
         *
         * DSA Article 17 requires naming the specific policy clause, and the
         * clause already lives on the enum. Duplicating it here by hand is how
         * the notice and the recorded reason end up saying different things.
         */
        foreach (ReasonCode::cases() as $reason) {
            if ($reason->group() === 'Process' || $reason->group() === 'Verification') {
                continue;
            }

            NotificationTemplate::query()->updateOrCreate(
                ['key' => 'enforcement.'.$reason->value],
                [
                    'name' => 'Enforcement — '.$reason->label(),
                    'audience' => 'member',
                    'channel' => 'in_app',
                    'category' => 'enforcement',
                    'subject' => 'Action has been taken on your account',
                    'body' => $reason->statement()
                        ."\n\nPolicy clause: {$reason->policyClause()}."
                        ."\n\nThis decision was made by {{ decided_by }}."
                        ."\n\nIf you believe this is wrong, you can appeal within {{ appeal_window_days }} days.",
                    'placeholders' => ['decided_by', 'appeal_window_days'],
                    'is_transactional' => true,
                    'is_active' => true,
                ],
            );

            $sort++;
        }

        $this->command?->info("Seeded {$sort} notification templates.");
    }
}
