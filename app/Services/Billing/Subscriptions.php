<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\AppUser;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Notifications\MemberNotifier;
use App\Support\Masters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Putting a member on a plan, and taking them off it.
 *
 * Every route to premium goes through here — staff granting it by hand, a
 * completed checkout, a store purchase, or the clock running out — so the
 * member's mirror columns, the subscription history and the audit trail can
 * never disagree about what somebody is paying for.
 *
 * THE ENTITLEMENT IS NEVER SHORTENED OR DOWNGRADED. A member may hold rows
 * from more than one source at once (a web plan until December and a store
 * plan renewing monthly); the mirror on app_users is always the best tier
 * and the latest end date across every active row — see refreshMirror().
 * A grant supersedes only rows of its own family: a web or staff grant
 * replaces the previous web or staff row, a store renewal replaces the
 * previous row for the SAME store subscription, and neither touches the
 * other's.
 */
final class Subscriptions
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly MemberNotifier $notifier,
    ) {}

    /**
     * Put a member on a plan until `$endsAt` (null for open-ended), by hand
     * or through the website's checkout.
     *
     * Any web or staff plan they are already on is closed first — one such
     * plan at a time, or "what am I paying for?" has two answers. Store rows
     * are left alone: the store keeps charging for them regardless.
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
        $subscription = DB::transaction(function () use ($member, $plan, $endsAt, $actor, $source, $billingPeriod, $note, $amount, $currency): Subscription {
            $this->closeOpenSubscriptions($member, 'cancelled', onlyWhere: fn ($q) => $q->whereNull('external_ref'));

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

            $this->refreshMirror($member);

            $this->logger->log(
                module: 'billing',
                action: $source === 'payment' ? 'plan_purchased' : 'plan_granted',
                subject: $member,
                description: "{$plan->name} given to {$member->display_name}".($endsAt ? ' until '.platform_date($endsAt) : ' (no end date)'),
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

        $this->announceStart($member, $plan, $endsAt);

        return $subscription;
    }

    /**
     * A store said this member is entitled: honour the store's calendar.
     *
     * The store's expiry is authoritative — no local arithmetic, no early-
     * renewal top-up. A renewal is a new transaction and a new row; the
     * previous row for the SAME store subscription is closed as superseded.
     * Every other active row (web, staff, the other store) stays, and the
     * mirror is recomputed from all of them, so a store purchase can never
     * shorten or downgrade what the member already had.
     */
    public function grantFromStore(
        AppUser $member,
        Plan $plan,
        Carbon $endsAt,
        string $store,
        string $externalRef,
        string $billingPeriod,
        ?bool $autoRenewing,
        ?float $amount,
        ?string $currency,
        string $environment,
        ?string $note = null,
    ): Subscription {
        $hadEntitlement = false;

        $subscription = DB::transaction(function () use ($member, $plan, $endsAt, $store, $externalRef, $billingPeriod, $autoRenewing, $amount, $currency, $environment, $note, &$hadEntitlement): Subscription {
            $hadEntitlement = Subscription::query()->where('app_user_id', $member->id)->active()
                ->where(fn ($q) => $q->where('external_ref', '!=', $externalRef)->orWhereNull('external_ref'))
                ->exists();

            // Superseded by this renewal.
            $this->closeOpenSubscriptions($member, 'expired', onlyWhere: fn ($q) => $q->where('external_ref', $externalRef));

            $subscription = Subscription::query()->create([
                'app_user_id' => $member->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'plan_name' => $plan->name,
                'status' => 'active',
                'source' => $store,
                'billing_period' => $billingPeriod,
                'external_ref' => $externalRef,
                'auto_renewing' => $autoRenewing,
                'starts_at' => now(),
                'ends_at' => $endsAt,
                'amount' => $amount,
                'currency' => $currency,
                'note' => $note,
            ]);

            $this->refreshMirror($member);

            $this->logger->log(
                module: 'billing',
                action: 'plan_purchased',
                subject: $member,
                description: "{$plan->name} bought by {$member->display_name} through ".($store === 'apple' ? 'the App Store' : 'Google Play').' until '.platform_date($endsAt),
                new: [
                    'plan' => $plan->slug,
                    'ends_at' => $endsAt->toIso8601String(),
                    'source' => $store,
                    'external_ref' => $externalRef,
                    'environment' => $environment,
                    'auto_renewing' => $autoRenewing,
                    'amount' => $amount,
                    'note' => $note,
                ],
            );

            return $subscription;
        });

        // Told once, when the plan starts; a renewal is the store's receipt to send.
        if (! $hadEntitlement) {
            $this->announceStart($member, $plan, $endsAt);
        }

        return $subscription;
    }

    /** Take a member off every plan now — staff's decision, so store rows go too (a store renewal will restore its own). */
    public function revoke(AppUser $member, ?User $actor = null, ?string $note = null): void
    {
        DB::transaction(function () use ($member, $actor, $note): void {
            $plan = $member->premium_tier;
            $this->closeOpenSubscriptions($member, 'cancelled', $note);

            $this->refreshMirror($member);

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
     * A store said a subscription ended — refund, revocation, expiry or a
     * hold the member has to resolve with the store. Only that subscription's
     * rows close; anything else the member holds keeps running.
     */
    public function revokeExternal(AppUser $member, string $store, string $externalRef, string $reason): void
    {
        DB::transaction(function () use ($member, $store, $externalRef, $reason): void {
            $rows = Subscription::query()->where('app_user_id', $member->id)->active()
                ->where('source', $store)->where('external_ref', $externalRef)->get();

            if ($rows->isEmpty()) {
                return;
            }

            $rows->each(fn (Subscription $row) => $row->forceFill([
                'status' => $reason === 'expired' ? 'expired' : 'cancelled',
                'ended_at' => now(),
                'auto_renewing' => false,
                'note' => trim(($row->note ?? '').' '.match ($reason) {
                    'revoked' => 'Refunded or revoked by the store.',
                    'hold' => 'On hold: the store could not collect payment.',
                    default => 'Expired at the store.',
                }),
            ])->save());

            $wasPremium = (bool) $member->is_premium;
            $this->refreshMirror($member);

            $this->logger->log(
                module: 'billing',
                action: 'plan_ended_by_store',
                subject: $member,
                description: "{$rows->first()->plan_name} {$reason} at ".($store === 'apple' ? 'the App Store' : 'Google Play')." for {$member->display_name}",
                new: ['reason' => $reason, 'source' => $store, 'external_ref' => $externalRef, 'still_premium' => (bool) $member->is_premium],
            );

            if ($wasPremium && ! $member->is_premium) {
                $this->notifier->email($member, 'billing.expired', [
                    'first_name' => str($member->display_name)->before(' ')->toString(),
                    'plan_name' => $rows->first()->plan_name,
                ], 'See plans', route('member.premium'));
            }
        });
    }

    /**
     * Support moving a store subscription to another account — somebody
     * signed up twice, or redeemed on the wrong one. Every row and order for
     * that store subscription moves, and both mirrors are recomputed.
     *
     * @return int how many subscription rows moved
     */
    public function reassignExternal(string $store, string $externalRef, AppUser $to, ?User $actor = null, ?string $note = null): int
    {
        return DB::transaction(function () use ($store, $externalRef, $to, $actor, $note): int {
            $rows = Subscription::query()->where('source', $store)->where('external_ref', $externalRef)->get();
            $fromIds = $rows->pluck('app_user_id')
                ->merge(Order::query()->where('gateway', $store)->where('gateway_ref', $externalRef)->pluck('app_user_id'))
                ->unique()->reject(fn (int $id): bool => $id === $to->id)->values();

            $rows->each(fn (Subscription $row) => $row->forceFill([
                'app_user_id' => $to->id,
                'note' => trim(($row->note ?? '').' Moved to this account by '.($actor?->name ?? 'staff').'.'.($note ? " {$note}" : '')),
            ])->save());

            Order::query()->where('gateway', $store)->where('gateway_ref', $externalRef)->update(['app_user_id' => $to->id]);

            foreach (AppUser::withTrashed()->whereIn('id', $fromIds)->get() as $from) {
                if (! $from->trashed()) {
                    $this->refreshMirror($from);
                }
            }

            $this->refreshMirror($to);

            $this->logger->log(
                module: 'billing',
                action: 'plan_reassigned',
                subject: $to,
                description: ($store === 'apple' ? 'App Store' : 'Google Play')." subscription {$externalRef} moved to {$to->display_name}",
                old: ['from_member_ids' => $fromIds->all()],
                new: ['to_member_id' => $to->id, 'source' => $store, 'external_ref' => $externalRef, 'rows' => $rows->count(), 'note' => $note],
                sensitive: true,
            );

            return $rows->count();
        });
    }

    /** The store's word on whether the subscription will renew, kept on its rows so the console and the app can say "renews" or "ends". */
    public function setAutoRenewing(AppUser $member, string $store, string $externalRef, ?bool $autoRenewing): void
    {
        if ($autoRenewing === null) {
            return;
        }

        Subscription::query()->where('app_user_id', $member->id)->active()
            ->where('source', $store)->where('external_ref', $externalRef)
            ->update(['auto_renewing' => $autoRenewing]);
    }

    /**
     * Recompute app_users.is_premium / premium_tier / premium_until from every
     * active row: the best tier and the latest end (open-ended wins). This is
     * the single place the mirror is written from subscription rows, and the
     * reason nothing can shorten or downgrade an entitlement by accident.
     */
    public function refreshMirror(AppUser $member): void
    {
        $active = Subscription::query()->where('app_user_id', $member->id)->active()->get();

        if ($active->isEmpty()) {
            $member->forceFill(['is_premium' => false, 'premium_tier' => null, 'premium_until' => null])->save();

            return;
        }

        $best = $this->entitling($active);
        $until = $active->contains(fn (Subscription $s): bool => $s->ends_at === null) ? null : $active->max('ends_at');

        $member->forceFill(['is_premium' => true, 'premium_tier' => $best->plan_slug, 'premium_until' => $until])->save();
    }

    /**
     * The row that gives the member their tier: best plan first, then the
     * one that lasts longest.
     *
     * @param  Collection<int, Subscription>  $active
     */
    public function entitling(Collection $active): Subscription
    {
        return $active->sort(function (Subscription $a, Subscription $b): int {
            $byRank = (Masters::plan($b->plan_slug)?->rank() ?? 0) <=> (Masters::plan($a->plan_slug)?->rank() ?? 0);

            if ($byRank !== 0) {
                return $byRank;
            }

            return ($b->ends_at?->timestamp ?? PHP_INT_MAX) <=> ($a->ends_at?->timestamp ?? PHP_INT_MAX);
        })->first();
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

                    if ($member === null) {
                        return;
                    }

                    // Another row may still carry them — a renewal that
                    // already replaced this one, or a plan from elsewhere.
                    $this->refreshMirror($member);

                    if (! $member->is_premium) {
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
                    $this->refreshMirror($member);

                    if (! $member->is_premium) {
                        $ended++;
                    }
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

    /**
     * After the transaction commits — including one we are nested inside.
     *
     * Sitting after our own DB::transaction() was not enough: checkout calls
     * grant() from within Checkout::fulfil()'s transaction, so on that path
     * "after the transaction" was still inside one, and a member was told
     * about a plan a later rollback would have taken away. DB::afterCommit
     * waits for the outermost commit, and runs immediately when there is no
     * transaction at all.
     */
    private function announceStart(AppUser $member, Plan $plan, ?Carbon $endsAt): void
    {
        DB::afterCommit(fn () => $this->notifier->email($member, 'billing.plan_started', [
            'first_name' => str($member->display_name)->before(' ')->toString(),
            'plan_name' => $plan->name,
            'until_clause' => $endsAt === null ? '' : ' until '.platform_date($endsAt),
        ], 'See your plan', route('member.premium')));
    }

    /** @param  null|callable(Builder): mixed  $onlyWhere */
    private function closeOpenSubscriptions(AppUser $member, string $status, ?string $note = null, ?callable $onlyWhere = null): void
    {
        Subscription::query()->where('app_user_id', $member->id)->active()
            ->when($onlyWhere !== null, fn ($q) => $onlyWhere($q))
            ->get()
            ->each(function (Subscription $subscription) use ($status, $note): void {
                $subscription->forceFill([
                    'status' => $status,
                    'ended_at' => now(),
                    'note' => $note ?? $subscription->note,
                ])->save();
            });
    }
}
