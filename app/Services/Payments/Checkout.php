<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\AppUser;
use App\Models\GatewayEvent;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\PaymentLog;
use App\Models\Plan;
use App\Services\Audit\ActivityLogger;
use App\Services\Billing\Subscriptions;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Buying a plan.
 *
 * The rule the whole class exists for: an order is fulfilled **once**, no
 * matter how many times it is confirmed. Both gateways can tell us about the
 * same payment three times — on the return page, by webhook, and again when a
 * webhook is retried — and a member must not end up with three subscriptions
 * or three charges' worth of time.
 */
final class Checkout
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly ActivityLogger $logger,
    ) {}

    /** The gateways an operator has switched on and which can take this currency. */
    public function availableGateways(?string $currency = null): Collection
    {
        $currency ??= Currency::code();

        return PaymentGateway::query()->active()->orderBy('sort_order')->get()
            ->filter(function (PaymentGateway $gateway) use ($currency): bool {
                $driver = $this->driverFor($gateway);

                return $driver !== null && $driver->supports($currency) && $this->hasKeys($gateway);
            })
            ->values();
    }

    public function isAvailable(): bool
    {
        return $this->availableGateways()->isNotEmpty();
    }

    /**
     * Start a purchase and return where to send the member.
     *
     * @throws PaymentFailed
     */
    public function start(AppUser $member, Plan $plan, string $period, PaymentGateway $gateway, string $returnUrl, string $cancelUrl): array
    {
        $driver = $this->driverFor($gateway);
        $currency = Currency::code();

        if ($driver === null || ! $driver->supports($currency)) {
            throw new PaymentFailed(
                "{$gateway->name} cannot charge in {$currency}.",
                'That payment method is not available for this currency.',
            );
        }

        $price = $period === 'yearly' ? $plan->yearly_price : $plan->monthly_price;

        if ($price === null || (float) $price < Currency::minimumCharge($currency)) {
            throw new PaymentFailed(
                "Plan {$plan->slug} has no usable {$period} price.",
                'That plan cannot be bought right now. Please contact support.',
            );
        }

        $order = Order::query()->create([
            'app_user_id' => $member->id,
            'purpose' => 'plan',
            'reference' => $plan->slug,
            'description' => $plan->name.' · '.($period === 'yearly' ? '12 months' : '1 month'),
            'amount_minor' => Currency::toMinor($price, $currency),
            'currency' => $currency,
            'billing_period' => $period,
            'gateway' => $gateway->slug,
            'status' => 'pending',
        ]);

        try {
            $started = $driver->startCheckout(
                $order,
                str_replace('__ORDER__', $order->uuid, $returnUrl),
                str_replace('__ORDER__', $order->uuid, $cancelUrl),
            );
        } catch (PaymentFailed $e) {
            $order->forceFill(['status' => 'failed', 'failure_reason' => Str::limit($e->getMessage(), 240)])->save();
            Log::warning('Checkout could not start: '.$e->getMessage());

            throw $e;
        }

        $order->forceFill(['gateway_ref' => $started['gateway_ref']])->save();

        return ['order' => $order, 'redirect_url' => $started['redirect_url']];
    }

    /**
     * Ask the gateway what happened and act on it.
     *
     * Used by the return page and by the "check again" button, so a member
     * whose webhook has not arrived is never left staring at "pending".
     */
    public function reconcile(Order $order): Order
    {
        if ($order->isPaid() || $order->gateway_ref === null) {
            return $order;
        }

        $driver = $this->driverFor($this->gatewayFor($order));

        if ($driver === null) {
            return $order;
        }

        try {
            $status = $driver->fetchStatus($order);
        } catch (Throwable $e) {
            Log::warning("Could not read order {$order->uuid} from {$order->gateway}: {$e->getMessage()}");

            return $order;
        }

        if ($status['paid']) {
            return $this->fulfil($order, $status['payment_ref'], $status['raw']);
        }

        if ($status['failed'] && $order->status === 'pending') {
            $order->forceFill([
                'status' => 'failed',
                'failure_reason' => 'The payment was not completed.',
                'gateway_payload' => $status['raw'],
            ])->save();
        }

        return $order;
    }

    /**
     * Give the member what they paid for.
     *
     * Locked and re-checked inside the transaction: the return page and the
     * webhook routinely land within the same second, and without this a member
     * would get two subscriptions for one payment.
     */
    public function fulfil(Order $order, ?string $paymentRef = null, array $raw = []): Order
    {
        return DB::transaction(function () use ($order, $paymentRef, $raw): Order {
            $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->isPaid()) {
                return $fresh ?? $order;
            }

            $fresh->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_ref' => $paymentRef ?? $fresh->payment_ref,
                'gateway_payload' => $raw ?: $fresh->gateway_payload,
                'failure_reason' => null,
            ])->save();

            $member = $fresh->appUser;
            $plan = $fresh->purpose === 'plan' ? Plan::query()->where('slug', $fresh->reference)->first() : null;

            if ($member !== null && $plan !== null) {
                $current = $this->subscriptions->currentFor($member);

                // Renewing early adds to what is left rather than throwing it
                // away — otherwise paying a week early costs the member a week.
                $from = $current?->ends_at !== null && $current->ends_at->isFuture() && $current->plan_slug === $plan->slug
                    ? $current->ends_at
                    : now();

                $endsAt = $fresh->billing_period === 'yearly' ? $from->copy()->addYear() : $from->copy()->addMonth();

                $subscription = $this->subscriptions->grant(
                    member: $member,
                    plan: $plan,
                    endsAt: $endsAt,
                    source: 'payment',
                    billingPeriod: $fresh->billing_period,
                    amount: Currency::fromMinor($fresh->amount_minor, $fresh->currency),
                    currency: $fresh->currency,
                );

                $fresh->forceFill(['subscription_id' => $subscription->id])->save();
            }

            $this->writePaymentLog($fresh, 'succeeded');

            $this->logger->log(
                module: 'billing',
                action: 'payment_received',
                subject: $member,
                description: "{$fresh->description} paid by {$member?->display_name} ({$fresh->formattedAmount()})",
                new: ['order' => $fresh->uuid, 'gateway' => $fresh->gateway, 'payment_ref' => $fresh->payment_ref],
            );

            return $fresh;
        });
    }

    /**
     * Handle a verified webhook.
     *
     * Returns false when the event has been seen before, which both gateways
     * do routinely on retry.
     */
    public function handleWebhook(string $gatewaySlug, WebhookEvent $event): bool
    {
        $order = $event->gatewayRef === null ? null : Order::query()
            ->where('gateway', $gatewaySlug)
            ->where('gateway_ref', $event->gatewayRef)
            ->first();

        // Recorded before acting: if fulfilment then fails, the retry is not
        // mistaken for a duplicate and skipped.
        $seen = GatewayEvent::query()->firstOrCreate(
            ['gateway' => $gatewaySlug, 'event_id' => $event->id],
            ['event_type' => $event->type, 'order_id' => $order?->id, 'payload' => $event->payload],
        );

        if (! $seen->wasRecentlyCreated) {
            return false;
        }

        if ($order === null) {
            return true;
        }

        if ($event->paid) {
            $this->fulfil($order, $event->paymentRef, $event->payload);

            return true;
        }

        if ($event->failed && $order->status === 'pending') {
            $order->forceFill([
                'status' => 'failed',
                'failure_reason' => 'The payment did not go through.',
                'gateway_payload' => $event->payload,
            ])->save();

            $this->writePaymentLog($order, 'failed');
        }

        return true;
    }

    public function driverFor(PaymentGateway $gateway): ?PaymentDriver
    {
        return match ($gateway->slug) {
            'stripe' => new StripeDriver($gateway),
            'razorpay' => new RazorpayDriver($gateway),
            // PayU and PayPal are in the catalogue but have no driver yet;
            // they stay unselectable rather than failing at the last step.
            default => null,
        };
    }

    public function gatewayFor(Order $order): PaymentGateway
    {
        return PaymentGateway::query()->where('slug', $order->gateway)->firstOrFail();
    }

    private function hasKeys(PaymentGateway $gateway): bool
    {
        $credentials = $gateway->credentials ?? [];

        foreach ($gateway->credentialFields() as $field) {
            // The webhook secret is only needed once the site is public, so a
            // gateway is usable for testing without it.
            if ($field !== 'webhook_secret' && empty($credentials[$field])) {
                return false;
            }
        }

        return true;
    }

    private function writePaymentLog(Order $order, string $status): void
    {
        try {
            PaymentLog::query()->create([
                'uuid' => (string) Str::uuid(),
                'app_user_id' => $order->app_user_id,
                'gateway' => $order->gateway,
                'gateway_reference' => $order->payment_ref ?? $order->gateway_ref,
                'product' => $order->description,
                'amount' => Currency::fromMinor($order->amount_minor, $order->currency),
                'currency' => $order->currency,
                'status' => $status,
                'failure_reason' => $order->failure_reason,
                'response' => $order->gateway_payload,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not write a payment log row: '.$e->getMessage());
        }
    }
}
