<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendCampaignPush;
use App\Models\PushCampaign;
use App\Services\Notifications\CampaignAudience;
use App\Support\PushSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Sends approved campaigns whose time has come.
 *
 * Driven by the scheduler rather than the browser: a campaign to fifty
 * thousand people cannot run inside a click, and an operator closing the tab
 * must not stop it half way. Each recipient gets a delivery-log row, so
 * "did it reach them?" is answerable per person.
 */
class SendCampaigns extends Command
{
    protected $signature = 'platform:send-campaigns {--id= : Send one campaign now, ignoring its schedule}';

    protected $description = 'Send approved push campaigns that are due';

    public function handle(): int
    {
        if (! PushSettings::enabled()) {
            $this->warn('Push is not configured, so nothing was sent.');

            return self::SUCCESS;
        }

        $campaigns = PushCampaign::query()
            ->where('status', 'scheduled')
            ->whereNotNull('approved_at')
            ->when($this->option('id'), fn ($q, $id) => $q->whereKey($id))
            ->when(! $this->option('id'), fn ($q) => $q->where('scheduled_for', '<=', now()))
            ->get();

        foreach ($campaigns as $campaign) {
            $this->send($campaign);
        }

        $this->line('Campaigns queued: '.$campaigns->count());

        return self::SUCCESS;
    }

    private function send(PushCampaign $campaign): void
    {
        // Claimed first, so two overlapping runs cannot both send it.
        $claimed = PushCampaign::query()
            ->whereKey($campaign->id)
            ->where('status', 'scheduled')
            ->update(['status' => 'sending', 'started_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $audience = $campaign->audience_filters['audience'] ?? 'all';

        // Counted up front so the totals start from the members who can never
        // receive this, rather than having to be reconciled afterwards.
        $skipped = $this->recordMembersWithoutDevice($campaign, $audience);

        $campaign->forceFill(['sent_count' => 0, 'failed_count' => $skipped])->save();

        $memberIds = CampaignAudience::query($audience)
            ->whereIn('id', fn ($q) => $q->select('app_user_id')->from('push_tokens')->distinct())
            ->pluck('id');

        if ($memberIds->isEmpty()) {
            $this->markComplete($campaign);
            $this->line("  {$campaign->name}: nobody to send to ({$skipped} with no device)");

            return;
        }

        /*
         * Handed to the queue in chunks rather than sent from this command.
         *
         * The scheduler tick now returns as soon as the work is queued, and
         * several workers share the Firebase round trips. The batch's finally()
         * closes the campaign off whether every chunk succeeded or not — a
         * campaign stuck on "sending" for ever is worse than one that reports
         * its failures.
         */
        $campaignId = $campaign->id;

        Bus::batch(
            $memberIds->chunk(100)
                ->map(fn ($chunk) => new SendCampaignPush($campaignId, $chunk->values()->all()))
                ->all()
        )
            ->name("campaign:{$campaignId}")
            ->finally(function () use ($campaignId): void {
                PushCampaign::query()
                    ->whereKey($campaignId)
                    ->update(['status' => 'sent', 'completed_at' => now()]);
            })
            ->dispatch();

        $this->line("  {$campaign->name}: queued for {$memberIds->count()}, {$skipped} with no device");
    }

    private function markComplete(PushCampaign $campaign): void
    {
        $campaign->forceFill(['status' => 'sent', 'completed_at' => now()])->save();
    }

    /**
     * Members in the audience with no device.
     *
     * Recorded rather than silently dropped, so the totals add up to the
     * audience size and "why did only 800 of 1,000 get it?" has an answer.
     */
    private function recordMembersWithoutDevice(PushCampaign $campaign, string $audience): int
    {
        $skipped = 0;

        $withoutDevice = CampaignAudience::query($audience)
            ->whereNotIn('id', fn ($q) => $q->select('app_user_id')->from('push_tokens')->distinct())
            ->select('id')
            ->get();

        foreach ($withoutDevice->chunk(500) as $chunk) {
            $rows = $chunk->map(fn ($member): array => [
                'push_campaign_id' => $campaign->id,
                'app_user_id' => $member->id,
                'title' => $campaign->title,
                'status' => 'failed',
                'failure_reason' => 'No device registered for notifications',
                'created_at' => now(),
            ])->all();

            DB::table('push_logs')->insert($rows);
            $skipped += count($rows);
        }

        return $skipped;
    }
}
