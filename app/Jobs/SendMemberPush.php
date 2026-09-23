<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Models\AppUser;
use App\Services\Notifications\MemberNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A push notification, sent off the request that caused it.
 *
 * Firebase is one HTTP call per registered device. Two people matching means
 * two members' worth of those, and doing it inline would put a stranger's
 * network latency inside the swipe that created the match — the single most
 * latency-sensitive action in the product.
 *
 * Carries the member's id rather than the model: by the time a worker runs
 * this, the row may have changed, and the current state is the one that
 * matters (a member banned in between should not get a cheerful push).
 */
class SendMemberPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param  array<string, string|int|null>  $values */
    public function __construct(
        private readonly int $memberId,
        private readonly string $templateKey,
        private readonly array $values = [],
        private readonly ?string $link = null,
    ) {}

    public function handle(MemberNotifier $notifier): void
    {
        $member = AppUser::query()->find($this->memberId);

        /*
         * Shadow-banned members are deliberately NOT skipped. A shadow ban the
         * member can detect is not a shadow ban, and an account that suddenly
         * stops buzzing is a tell. They receive nothing in practice because
         * they are out of everybody's deck, so nothing reaches this point for
         * them anyway — but the rule is stated rather than left to luck.
         */
        $silenced = [AccountStatus::Suspended, AccountStatus::Banned, AccountStatus::Deactivated];

        if ($member === null || in_array($member->account_status, $silenced, true)) {
            return;
        }

        $notifier->push($member, $this->templateKey, $this->values, $this->link);
    }
}
