<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\AppUser;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Notifications\MemberNotifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Putting a member on a plan, and taking them off it.
 *
 * Every route to premium goes through here — staff granting it by hand, a
 * completed checkout, or the clock running out — so the member's mirror
 * columns, the subscription history and the audit trail can never disagree
 * about what somebody is paying for.
 */
final class Subscriptions
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly MemberNotifier $notifier,
    ) {}

    /**
     * Put a member on a plan until `$endsAt` (null for open-ended).
     *
     * Any plan they are already on is closed first: a member has one plan at a
     * time, and two overlapping rows would make "what am I paying for?"
     * unanswerable.
     */
    public function grant(
        AppUser $member,
        Plan $plan,
        ?Carbon $endsAt = null,
        ?User $actor = null,
        string $source = 'manual',
        string $billingPeriod = 'custom',
        ?string $note = null,
        ?float $amount = null,
        ?string $currency = null,
    ): Subscription {
        return DB::transaction(function () use ($member, $plan, $endsAt, $actor, $source, $billingPeriod, $note, $amount, $currency): Subscription {
            $this->closeOpenSubscriptions($member, 'cancelled');

            $subscription = Subscription::query()->create([
                'app_user_id' => $member->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'plan_name' => $plan->name,
                'status' => 'active',
                'source' => $source,
                'billing_period' => $billingPeriod,
                'starts_at' => now(),
                'ends_at' => $endsAt,
                'amount' => $amount,
                'currency' => $currency,
                'granted_by' => $actor?->id,
                'note' => $note,
            ]);

            $member->forceFill([
                'is_premium' => true,
                'premium_tier' => $plan->slug,
                'premium_until' => $endsAt,
            ])->save();

            $this->logger->log(
                module: 'billing',
                action: $source === 'payment' ? 'plan_purchased' : 'plan_granted',
                subject: $member,
                description: "{$plan->name} given to {$member->display_name}".($endsAt ? ' until '.veyra_date($endsAt) : ' (no end date)'),
                new: [
                    'plan' => $plan->slug,
                    'ends_at' => $endsAt?->toIso8601String(),
                    'source' => $source,
                    'amount' => $amount,
                    'note' => $note,
                ],
            );

            return $subscription;
        });

        $this->notifier->email($member, 'billing.plan_started', [
            'first_name' => str($member->display_name)->before(' ')->toString(),
            'plan_name' => $plan->name,
            'until_clause' => $endsAt === null ? '' : ' until '.veyra_date($endsAt),
        ], 'See your plan', route('member.premium'));

        return $subscription;
    }

    /** Take a member off their plan now. */
    public function revoke(AppUser $member, ?User $actor = null, ?string $note = null): void
    {
        DB::transaction(function () use ($member, $actor, $note): void {
            $plan = $member->premium_tier;
            $this->closeOpenSubscriptions($member, 'cancelled', $note);

            $member->forceFill([
                'is_premium' => false,
                'premium_tier' => null,
                'premium_until' => null,
            ])->save();

            $this->logger->log(
                module: 'billing',
                action: 'plan_removed',
                subject: $member,
                description: "Plan removed from {$member->display_name}",
                old: ['plan' => $plan],
                new: ['note' => $note, 'by' => $actor?->name],
            );
        });
    }

    /**
     * End every subscription whose date has passed.
     *
     * Runs on the scheduler. It also repairs members whose mirror says premium
     * while no subscription backs it — the pre-checkout demo data is like that,
     * and so is anything set by hand in the database.
     *
     * @return int how many members were taken off a plan
     */
    public function expireDue(): int
    {
        $ended = 0;

        Subscription::query()->lapsed()->with('appUser')->chunkById(100, function ($subscriptions) use (&$ended): void {
            foreach ($subscriptions as $subscription) {
                DB::transaction(function () use ($subscription, &$ended): void {
                    $subscription->forceFill(['status' => 'expired', 'ended_at' => now()])->save();

                    $member = $subscription->appUser;

                    // Only clear the mirror if this is still the plan they are
                    // on — a renewal may have already replaced it.
                    if ($member !== null && $member->premium_tier === $subscription->plan_slug) {
                        $member->forceFill([
                            'is_premium' => false,
                            'premium_tier' => null,
                            'premium_until' => null,
                        ])->save();

                        $this->logger->log(
                            module: 'billing',
                            action: 'plan_expired',
                            subject: $member,
                            description: "{$subscription->plan_name} ended for {$member->display_name}",
                        );

                        // The "it ends today" email the operator asked for:
                        // sent when it actually ends, not on a guess.
                        $this->notifier->email($member, 'billing.expired', [
                            'first_name' => str($member->display_name)->before(' ')->toString(),
                            'plan_name' => $subscription->plan_name,
                        ], 'See plans', route('member.premium'));

                        $ended++;
                    }
                });
            }
        });

        // Members whose premium_until has passed but who have no subscription
        // row at all (seeded or hand-edited). Without this they stay premium
        // for ever.
        AppUser::query()
            ->where('is_premium', true)
            ->whereNotNull('premium_until')
            ->where('premium_until', '<=', now())
            ->chunkById(200, function ($members) use (&$ended): void {
                foreach ($members as $member) {
                    $member->forceFill([
                        'is_premium' => false,
                        'premium_tier' => null,
                        'premium_until' => null,
                    ])->save();

                    $ended++;
                }
            });

        return $ended;
    }

    public function currentFor(AppUser $member): ?Subscription
    {
        return Subscription::query()->where('app_user_id', $member->id)->active()->latest('starts_at')->first();
    }

    /** @return Collection<int, Subscription> */
    public function historyFor(AppUser $member)
    {
        return Subscription::query()->where('app_user_id', $member->id)
            ->with('grantedBy')->latest('starts_at')->limit(50)->get();
    }

    private function closeOpenSubscriptions(AppUser $member, string $status, ?string $note = null): void
    {
        Subscription::query()->where('app_user_id', $member->id)->active()->get()
            ->each(function (Subscription $subscription) use ($status, $note): void {
                $subscription->forceFill([
                    'status' => $status,
                    'ended_at' => now(),
                    'note' => $note ?? $subscription->note,
                ])->save();
            });
    }
}
