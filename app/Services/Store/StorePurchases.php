<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Models\AppUser;
use App\Models\GatewayEvent;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\Subscriptions;
use App\Services\Payments\Checkout;
use App\Support\Currency;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Honouring what a member bought in an app store.
 *
 * Two entry points, one fulfilment path. The app posts a receipt after a
 * purchase or a restore; the stores post notifications on renewal, refund and
 * expiry. Both end in Checkout::fulfil() with a StoreGrant, so the locked,
 * once-only fulfilment that already protects the web gateways protects
 * these too. Every store transaction is an `orders` row keyed uniquely by
 * (gateway, payment_ref); every notification is a `gateway_events` row whose
 * processed_at is written only after the work committed.
 *
 * The rules live here, not in the app: entitlement, ownership, family
 * sharing, what a deleted account's renewal does.
 */
final class StorePurchases
{
    public function __construct(
        private readonly StoreDrivers $drivers,
        private readonly Checkout $checkout,
        private readonly Subscriptions $subscriptions,
    ) {}

    /**
     * The app says "I bought this". The store is asked, and what it says goes.
     *
     * Idempotent: the same transaction posted again finds its paid order and
     * changes nothing.
     *
     * @throws StoreFailed reason receipt_invalid | product_unknown | receipt_owned_elsewhere | store_unavailable
     */
    public function redeem(AppUser $member, string $store, string $claimedProductId, string $token): Order
    {
        $driver = $this->driver($store);
        $transaction = $driver->verify($token);

        if (! $transaction->isEntitled()) {
            throw new StoreFailed("The {$store} transaction {$transaction->transactionId} is {$transaction->state}.", 'receipt_invalid', 'That purchase is not active.');
        }

        $mapping = Plan::forStoreProduct($store, $transaction->productId);

        if ($mapping === null) {
            throw new StoreFailed("No plan is mapped to {$store} product {$transaction->productId}.", 'product_unknown', 'That product is not on sale here.');
        }

        if ($claimedProductId !== $transaction->productId) {
            Log::info("Member {$member->uuid} claimed {$store} product {$claimedProductId}; the store says {$transaction->productId}. The store wins.");
        }

        $this->assertOwnership($member, $transaction);

        $order = $this->fulfil($member, $transaction, $mapping['plan'], $mapping['period']);

        $this->acknowledge($driver, $transaction);

        return $order;
    }

    /**
     * A store said something changed. Same shape as Checkout::handleWebhook():
     * recorded first, acted on, and only then marked processed — so a failure
     * part-way leaves the event retryable and a repeat of a processed event
     * changes nothing.
     *
     * Returns false when the delivery was seen and handled before.
     *
     * @throws StoreFailed|\Throwable anything thrown leaves processed_at null on purpose; the controller answers 500 and the store retries
     */
    public function handleNotification(string $store, StoreNotification $notification): bool
    {
        $seen = GatewayEvent::query()->firstOrCreate(
            ['gateway' => $store, 'event_id' => $notification->id],
            ['event_type' => $notification->type.($notification->subtype !== null ? "/{$notification->subtype}" : ''), 'payload' => $notification->raw],
        );

        if ($seen->isProcessed()) {
            return false;
        }

        if ($notification->externalRef === null) {
            // Test pings and events about nothing we sell.
            $seen->markProcessed();

            return true;
        }

        $driver = $this->driver($store);
        $transaction = $notification->transaction ?? $driver->verify($notification->externalRef);

        $members = $this->holders($store, $transaction->originalTransactionId);

        if ($members->isEmpty() && $transaction->appAccountToken !== null) {
            // The app crashed between the purchase and posting the receipt.
            // The uuid it attached at purchase time still tells us who.
            $member = AppUser::withTrashed()->where('uuid', $transaction->appAccountToken)->first();
            $members = $member === null ? $members : collect([$member]);
        }

        if ($members->isEmpty()) {
            Log::warning("A {$store} {$notification->type} notification for {$transaction->originalTransactionId} matches no member. Recorded and ignored; a restore from the app will attach it.");
            $seen->markProcessed();

            return true;
        }

        foreach ($members as $member) {
            if ($member->trashed()) {
                /*
                 * The account was deleted but the store keeps billing: we
                 * cannot cancel a store subscription on the member's behalf.
                 * Nothing is granted to a pseudonymised row; the member was
                 * told at deletion time to cancel it themselves.
                 */
                Log::info("A {$store} {$notification->type} notification for {$transaction->originalTransactionId} concerns deleted member {$member->uuid}. Ignored.");

                continue;
            }

            $this->apply($member, $transaction, $driver);
        }

        $seen->markProcessed();

        return true;
    }

    // ---- the rules -------------------------------------------------------------

    /**
     * The verified STATE decides, whatever the notification called itself:
     * entitled -> fulfil the transaction (idempotent per transactionId);
     * expired, revoked or on hold -> end the store rows; anything else waits.
     */
    private function apply(AppUser $member, StoreTransaction $transaction, StoreDriver $driver): void
    {
        if ($transaction->isEntitled()) {
            $mapping = Plan::forStoreProduct($transaction->store, $transaction->productId);

            if ($mapping === null) {
                Log::warning("No plan is mapped to {$transaction->store} product {$transaction->productId}; member {$member->uuid} gets nothing from transaction {$transaction->transactionId}.");

                return;
            }

            $this->fulfil($member, $transaction, $mapping['plan'], $mapping['period']);
            $this->acknowledge($driver, $transaction);

            return;
        }

        if (in_array($transaction->state, ['expired', 'revoked', 'hold'], true)) {
            $this->subscriptions->revokeExternal($member, $transaction->store, $transaction->originalTransactionId, $transaction->state);
        }
    }

    /**
     * §4 / Gap 2. A purchased transaction belongs to the first member who
     * presented it; support moves it by hand. A family-shared transaction
     * carries the purchaser's originalTransactionId with ownership
     * FAMILY_SHARED, and every family member may redeem it on their own
     * account.
     *
     * @throws StoreFailed reason receipt_owned_elsewhere
     */
    private function assertOwnership(AppUser $member, StoreTransaction $transaction): void
    {
        if ($transaction->isFamilyShared()) {
            return;
        }

        $owner = Order::query()
            ->where('gateway', $transaction->store)
            ->where('gateway_ref', $transaction->originalTransactionId)
            ->where('payment_ref', 'not like', '%@%') // family rows carry the member uuid after an @
            ->value('app_user_id');

        if ($owner !== null && (int) $owner !== $member->id) {
            throw new StoreFailed(
                "{$transaction->store} subscription {$transaction->originalTransactionId} belongs to member #{$owner}, not #{$member->id}.",
                'receipt_owned_elsewhere',
                'This purchase is already attached to another account. If that account was yours, contact support.',
            );
        }
    }

    /**
     * One order per store transaction, fulfilled once — the same locked path
     * the web gateways use, with the store's expiry as the calendar.
     *
     * The order is committed BEFORE fulfilment, as a web order is created at
     * checkout before its webhook arrives: if fulfilment then fails, the
     * pending order stays on the books as something owed to the member, and
     * the store's retry pays it.
     */
    private function fulfil(AppUser $member, StoreTransaction $transaction, Plan $plan, string $period): Order
    {
        // Family members receive a transaction whose id can equal the
        // purchaser's, so their orders are keyed with their own uuid.
        $paymentRef = $transaction->isFamilyShared() ? "{$transaction->transactionId}@{$member->uuid}" : $transaction->transactionId;

        $order = Order::query()->where('gateway', $transaction->store)->where('payment_ref', $paymentRef)->first();

        if ($order === null) {
            try {
                $order = Order::query()->create([
                    'app_user_id' => $member->id,
                    'purpose' => 'plan',
                    'reference' => $plan->slug,
                    'description' => $plan->name.' · '.($period === 'yearly' ? '12 months' : '1 month')
                        .' · '.($transaction->store === 'apple' ? 'App Store' : 'Google Play')
                        .($transaction->isFamilyShared() ? ' · Family Sharing' : ''),
                    'amount_minor' => $transaction->amount !== null && $transaction->currency !== null
                        ? Currency::toMinor($transaction->amount, $transaction->currency)
                        : 0,
                    'currency' => $transaction->currency ?? Currency::code(),
                    'billing_period' => $period,
                    'gateway' => $transaction->store,
                    'gateway_ref' => $transaction->originalTransactionId,
                    'payment_ref' => $paymentRef,
                    'status' => 'pending',
                    'environment' => $transaction->environment,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two deliveries of the same transaction in the same instant.
                // The other one created the row; fulfil() will find it paid
                // or lock it and pay it once.
                $order = Order::query()->where('gateway', $transaction->store)->where('payment_ref', $paymentRef)->firstOrFail();
            }
        }

        return $this->checkout->fulfil($order, $paymentRef, $transaction->raw, new StoreGrant($transaction, $plan, $period));
    }

    /** Google refunds an unacknowledged purchase after three days. After commit only: acknowledging a rolled-back grant would be lying. */
    private function acknowledge(StoreDriver $driver, StoreTransaction $transaction): void
    {
        DB::afterCommit(function () use ($driver, $transaction): void {
            try {
                $driver->acknowledge($transaction);
            } catch (StoreFailed $e) {
                // The next notification for this token tries again.
                Log::warning("Could not acknowledge {$transaction->store} transaction {$transaction->transactionId}: {$e->getMessage()}");
            }
        });
    }

    /**
     * Every member holding this store subscription, deleted ones included so
     * the caller can decide what a deleted account's renewal does.
     *
     * @return Collection<int, AppUser>
     */
    private function holders(string $store, string $externalRef): Collection
    {
        $ids = Subscription::query()->where('source', $store)->where('external_ref', $externalRef)->pluck('app_user_id')
            ->merge(Order::query()->where('gateway', $store)->where('gateway_ref', $externalRef)->pluck('app_user_id'))
            ->unique()
            ->values();

        return $ids->isEmpty() ? collect() : AppUser::withTrashed()->whereIn('id', $ids)->get();
    }

    /** @throws StoreFailed reason store_unavailable when the store is switched off */
    private function driver(string $store): StoreDriver
    {
        return $this->drivers->for($store) ?? throw new StoreFailed(
            "Purchases through {$store} are not enabled.",
            'store_unavailable',
            'In-app purchases are not available right now.',
        );
    }
}
