<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AppUser;
use App\Models\PushCampaign;
use App\Services\Push\FcmSender;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One chunk of a push campaign.
 *
 * Campaigns used to be sent by the scheduler in a single serial loop: one
 * Firebase round trip per device, in order, inside `platform:send-campaigns`.
 * A campaign to a real audience would still be running when the next scheduler
 * tick arrived — survivable only because the command refuses to overlap
 * itself, which means the tail of a big campaign delays every other one.
 *
 * Chunked into batched jobs instead, so several workers share the work and the
 * scheduler tick returns immediately.
 */
class SendCampaignPush implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 2;

    /** @param  array<int, int>  $memberIds */
    public function __construct(
        private readonly int $campaignId,
        private readonly array $memberIds,
    ) {}

    public function handle(FcmSender $sender): void
    {
        // A campaign cancelled mid-flight stops here rather than finishing the
        // queue that was already built for it.
        if ($this->batch()?->cancelled()) {
            return;
        }

        $campaign = PushCampaign::query()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        $message = new PushMessage(
            title: $campaign->title,
            body: $campaign->body,
            link: $campaign->deep_link ?: route('member.discover'),
            data: ['campaign' => (string) $campaign->uuid],
        );

        $sent = 0;
        $failed = 0;

        foreach (AppUser::query()->whereIn('id', $this->memberIds)->get() as $member) {
            try {
                $result = $sender->sendToMember($member, $message, $campaign->id);
                $result['sent'] > 0 ? $sent++ : $failed++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        // Incremented in SQL: chunks land concurrently, and a read-then-write
        // here would lose whole chunks' worth of the totals the console shows.
        $campaign->incrementEach(['sent_count' => $sent, 'failed_count' => $failed]);
    }
}
