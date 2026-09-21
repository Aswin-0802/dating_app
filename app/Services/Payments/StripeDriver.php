<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe, through Checkout Sessions.
 *
 * The hosted page is used rather than Stripe's JavaScript: the member leaves
 * for stripe.com and comes back, which means no card details ever touch this
 * server and there is nothing to keep in step with Stripe's front-end SDK.
 */
final class StripeDriver implements PaymentDriver
{
    private const API = 'https://api.stripe.com/v1';

    public function __construct(private readonly PaymentGateway $gateway) {}

    public function slug(): string
    {
        return 'stripe';
    }

    public function supports(string $currency): bool
    {
        return in_array(strtoupper($currency), ['USD', 'EUR', 'GBP', 'INR'], true);
    }

    public function startCheckout(Order $order, string $returnUrl, string $cancelUrl): array
    {
        $response = $this->client()
            ->withHeaders(['Idempotency-Key' => 'order_'.$order->uuid])
            ->asForm()
            ->post(self::API.'/checkout/sessions', [
                'mode' => 'payment',
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($order->currency),
                        'unit_amount' => $order->amount_minor,
                        'product_data' => ['name' => $order->description],
                    ],
                ]],
                // Stripe substitutes the session id into this placeholder.
                'success_url' => $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancelUrl,
                'client_reference_id' => $order->uuid,
                'customer_email' => $order->appUser?->email,
                'metadata' => ['order_uuid' => $order->uuid, 'plan' => (string) $order->reference],
                'payment_intent_data' => ['metadata' => ['order_uuid' => $order->uuid]],
            ]);

        if (! $response->successful()) {
            throw new PaymentFailed('Stripe refused to open a checkout session: '.$response->body());
        }

        return [
            'redirect_url' => (string) $response->json('url'),
            'gateway_ref' => (string) $response->json('id'),
        ];
    }

    public function readReturn(Request $request, Order $order): ReturnHint
    {
        // Stripe signs nothing on the return; the session id is a lookup key,
        // not evidence. Everything is decided by fetchStatus().
        $sessionId = (string) $request->query('session_id', '');

        return new ReturnHint(
            signatureValid: $sessionId !== '' && $sessionId === $order->gateway_ref,
            looksPaid: false,
        );
    }

    public function fetchStatus(Order $order): array
    {
        if ($order->gateway_ref === null) {
            return ['paid' => false, 'failed' => false, 'payment_ref' => null, 'raw' => []];
        }

        $response = $this->client()->get(self::API.'/checkout/sessions/'.$order->gateway_ref);

        if (! $response->successful()) {
            return ['paid' => false, 'failed' => false, 'payment_ref' => null, 'raw' => ['error' => $response->json()]];
        }

        $session = $response->json();

        return [
            // "no_payment_required" covers a 100% discount, which is still done.
            'paid' => in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true),
            'failed' => ($session['status'] ?? '') === 'expired',
            'payment_ref' => is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
            'raw' => $session,
        ];
    }

    public function readWebhook(Request $request): WebhookEvent
    {
        $this->verifySignature($request);

        $payload = $request->json()->all();
        $type = (string) ($payload['type'] ?? '');
        $session = $payload['data']['object'] ?? [];

        return new WebhookEvent(
            id: (string) ($payload['id'] ?? ''),
            type: $type,
            paid: $type === 'checkout.session.completed'
                ? in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true)
                : $type === 'checkout.session.async_payment_succeeded',
            failed: in_array($type, ['checkout.session.async_payment_failed', 'checkout.session.expired'], true),
            gatewayRef: is_string($session['id'] ?? null) ? $session['id'] : null,
            paymentRef: is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
            payload: $payload,
        );
    }

    /**
     * Stripe-Signature: t=…,v1=…
     *
     * Only v1 is compared — a v0 is deliberately sent for test events, and
     * accepting it would let anyone replay one. The five-minute window is
     * Stripe's own default and is what stops a captured request being useful
     * later.
     */
    private function verifySignature(Request $request): void
    {
        $secret = (string) ($this->gateway->credentials['webhook_secret'] ?? '');

        if ($secret === '') {
            throw new PaymentFailed('No Stripe webhook secret is configured.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', (string) $request->header('Stripe-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't') {
                $timestamp = $value;
            }

            if ($key === 'v1' && $value !== null) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new PaymentFailed('Stripe webhook had no usable signature.');
        }

        if (abs(time() - (int) $timestamp) > 300) {
            throw new PaymentFailed('Stripe webhook is older than five minutes.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new PaymentFailed('Stripe webhook signature did not match.');
    }

    private function client(): PendingRequest
    {
        $secret = (string) ($this->gateway->credentials['secret_key'] ?? '');

        if ($secret === '') {
            throw new PaymentFailed('No Stripe secret key is configured.');
        }

        // Deliberately not ->throw(): every call site checks the response, so a
        // gateway outage becomes a handled failure rather than a 500 mid-payment.
        return Http::withBasicAuth($secret, '')->timeout(20);
    }
}
