<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PushCampaign;
use App\Services\Notifications\CampaignAudience;
use App\Services\Push\FcmSender;
use App\Services\Push\PushMessage;
use App\Support\PushSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

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
    protected $signature = 'veyra:send-campaigns {--id= : Send one campaign now, ignoring its schedule}';

    protected $description = 'Send approved push campaigns that are due';

    public function handle(FcmSender $sender): int
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
            $this->send($campaign, $sender);
        }

        $this->line('Campaigns sent: '.$campaigns->count());

        return self::SUCCESS;
    }

    private function send(PushCampaign $campaign, FcmSender $sender): void
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
        $message = new PushMessage(
            title: $campaign->title,
            body: $campaign->body,
            link: $campaign->deep_link ?: route('member.discover'),
            data: ['campaign' => (string) $campaign->uuid],
        );

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        CampaignAudience::query($audience)
            ->whereIn('id', fn ($q) => $q->select('app_user_id')->from('push_tokens')->distinct())
            ->chunkById(200, function ($members) use ($campaign, $sender, $message, &$sent, &$failed): void {
                foreach ($members as $member) {
                    try {
                        $result = $sender->sendToMember($member, $message, $campaign->id);
                        $result['sent'] > 0 ? $sent++ : $failed++;
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                    }
                }
            });

        // Members in the audience with no device: recorded rather than
        // silently dropped, so the totals add up to the audience size.
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

        $campaign->forceFill([
            'status' => 'sent',
            'completed_at' => now(),
            'sent_count' => $sent,
            'failed_count' => $failed + $skipped,
        ])->save();

        $this->line("  {$campaign->name}: {$sent} sent, {$failed} failed, {$skipped} with no device");
    }
}
