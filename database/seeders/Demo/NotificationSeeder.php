<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo push campaigns and their delivery logs.
 *
 * Delivery outcomes are generated per recipient and then aggregated back onto
 * the campaign, so the counters on the campaign list and the rows in the log
 * always agree. Writing plausible-looking totals directly would be quicker and
 * would fall apart the moment anybody clicked through.
 */
class NotificationSeeder extends Seeder
{
    public function __construct(private readonly float $scale = 1.0) {}

    /** @return array<int, array{0: string, 1: string, 2: string, 3: string}> */
    private const CAMPAIGNS = [
        ['Weekend nudge', 'Your weekend starts here', 'New people joined near you this week.', 'Active in last 30 days'],
        ['Verification push', 'Get verified in a minute', 'Verified profiles get noticeably more matches.', 'Unverified members'],
        ['Silent matches', 'You have unanswered matches', 'Someone has to go first.', 'Members with a silent match'],
        ['Profile completion', 'Finish your profile', 'Profiles with photos get far more attention.', 'Profile under 60% complete'],
        ['Winback 30d', 'It has been a while', 'New people have joined since you were last here.', 'Inactive 30+ days'],
        ['Likes waiting', 'People are waiting', 'You have likes you have not seen.', 'Members with unseen likes'],
        ['New city launch', 'Veyra is now in your city', 'Hundreds of people just joined nearby.', 'Focus cities'],
        ['Safety reminder', 'Meeting someone new?', 'A few things worth knowing before a first date.', 'All active members'],
    ];

    public function run(): void
    {
        $faker = fake();
        $faker->seed(config('veyra.seed.faker_seed', 20260917) + 4);

        $staff = DB::table('users')->pluck('id')->all();
        $templateIds = NotificationTemplate::query()->pluck('id')->all();
        $memberIds = DB::table('app_users')->pluck('id')->all();

        if ($memberIds === []) {
            return;
        }

        $campaigns = 0;
        $logs = 0;

        foreach (self::CAMPAIGNS as $index => [$name, $title, $body, $audience]) {
            $status = match (true) {
                $index === 0 => 'draft',
                $index === 1 => 'scheduled',
                default => 'sent',
            };

            $startedAt = Carbon::now()->subDays($faker->numberBetween(3, 90));

            // Recipients are a real slice of the member base, so the estimate on
            // the campaign matches the number of log rows beneath it.
            $recipients = $status === 'sent'
                ? $faker->randomElements($memberIds, min(
                    count($memberIds),
                    (int) round($faker->numberBetween(300, 2400) * $this->scale),
                ))
                : [];

            $author = $faker->randomElement($staff);

            $campaignId = DB::table('push_campaigns')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'notification_template_id' => $templateIds ? $faker->randomElement($templateIds) : null,
                'title' => $title,
                'body' => $body,
                'audience_label' => $audience,
                'audience_filters' => json_encode(['label' => $audience]),
                'estimated_recipients' => $status === 'sent' ? count($recipients) : $faker->numberBetween(400, 3000),
                'status' => $status,
                'scheduled_for' => $status === 'scheduled' ? Carbon::now()->addDays(2) : null,
                'started_at' => $status === 'sent' ? $startedAt : null,
                'completed_at' => $status === 'sent' ? $startedAt->copy()->addMinutes(12) : null,
                'created_by' => $author,
                // The draft is left unapproved on purpose, so the approval
                // banner has something to point at on first load.
                'approved_by' => $status === 'draft' ? null : $faker->randomElement(
                    array_values(array_filter($staff, fn ($id) => $id !== $author)),
                ),
                'approved_at' => $status === 'draft' ? null : $startedAt->copy()->subHours(4),
                'created_at' => $startedAt->copy()->subDay(),
                'updated_at' => $startedAt,
            ]);

            $campaigns++;

            if ($recipients === []) {
                continue;
            }

            $logs += $this->seedLogs($faker, $campaignId, $title, $recipients, $startedAt);
        }

        $this->aggregateCounters();

        $this->command?->info("Seeded {$campaigns} campaigns and ".number_format($logs).' delivery records.');
    }

    /** @param array<int, int> $recipients */
    private function seedLogs($faker, int $campaignId, string $title, array $recipients, Carbon $startedAt): int
    {
        $rows = [];
        $total = 0;

        foreach ($recipients as $memberId) {
            // 4% fail, mostly stale tokens; of those delivered, 18-31% open.
            $failed = $faker->boolean(4);
            $opened = ! $failed && $faker->boolean($faker->numberBetween(18, 31));

            $sentAt = $startedAt->copy()->addSeconds($faker->numberBetween(0, 720));

            $rows[] = [
                'push_campaign_id' => $campaignId,
                'app_user_id' => $memberId,
                'title' => $title,
                'status' => $failed ? 'failed' : ($opened ? 'opened' : 'delivered'),
                'failure_reason' => $failed
                    ? $faker->randomElement([
                        'Token no longer registered',
                        'Device unreachable',
                        'Notifications disabled',
                    ])
                    : null,
                'sent_at' => $sentAt,
                'opened_at' => $opened ? $sentAt->copy()->addMinutes($faker->numberBetween(1, 600)) : null,
                'created_at' => $sentAt,
            ];

            $total++;

            if (count($rows) >= 2000) {
                DB::table('push_logs')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('push_logs')->insert($rows);
        }

        return $total;
    }

    /**
     * Roll delivery outcomes back onto the campaigns.
     *
     * One UPDATE rather than a query per campaign, and it guarantees the
     * headline numbers are derived from the rows rather than asserted beside
     * them.
     */
    private function aggregateCounters(): void
    {
        DB::statement("
            UPDATE push_campaigns c
            JOIN (
                SELECT push_campaign_id,
                       COUNT(*) AS sent,
                       SUM(status IN ('delivered','opened')) AS delivered,
                       SUM(status = 'opened') AS opened,
                       SUM(status = 'failed') AS failed
                FROM push_logs
                WHERE push_campaign_id IS NOT NULL
                GROUP BY push_campaign_id
            ) agg ON agg.push_campaign_id = c.id
            SET c.sent_count = agg.sent,
                c.delivered_count = agg.delivered,
                c.opened_count = agg.opened,
                c.failed_count = agg.failed
        ");
    }
}
