<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Razorpay, through Payment Links.
 *
 * Payment Links rather than their Checkout modal: a link is a plain redirect,
 * so the member app needs none of Razorpay's JavaScript and the flow is the
 * same shape as Stripe's — which is what keeps one checkout screen able to
 * serve both.
 */
final class RazorpayDriver implements PaymentDriver
{
    private const API = 'https://api.razorpay.com/v1';

    public function __construct(private readonly PaymentGateway $gateway) {}

    public function slug(): string
    {
        return 'razorpay';
    }

    public function supports(string $currency): bool
    {
        // Razorpay settles in INR; other currencies need international
        // payments enabled on the account, which we cannot detect from here.
        return strtoupper($currency) === 'INR';
    }

    public function startCheckout(Order $order, string $returnUrl, string $cancelUrl): array
    {
        $response = $this->client()->post(self::API.'/payment_links', [
            'amount' => $order->amount_minor,
            'currency' => strtoupper($order->currency),
            'description' => $order->description,
            'reference_id' => $order->uuid,
            'customer' => array_filter([
                'name' => $order->appUser?->display_name,
                'email' => $order->appUser?->email,
                'contact' => $order->appUser?->phone,
            ]),
            // Razorpay's own email and SMS are off: the member is standing in
            // front of the payment page, and a second "pay now" message an
            // hour later reads as a scam.
            'notify' => ['sms' => false, 'email' => false],
            'reminder_enable' => false,
            'notes' => ['order_uuid' => $order->uuid, 'plan' => (string) $order->reference],
            'callback_url' => $returnUrl,
            'callback_method' => 'get',
        ]);

        if (! $response->successful()) {
            throw new PaymentFailed('Razorpay refused to create a payment link: '.$response->body());
        }

        return [
            'redirect_url' => (string) $response->json('short_url'),
            'gateway_ref' => (string) $response->json('id'),
        ];
    }

    /**
     * Razorpay signs its return, over
     * link_id | reference_id | status | payment_id, keyed with the API secret
     * (not the webhook secret).
     */
    public function readReturn(Request $request, Order $order): ReturnHint
    {
        $secret = (string) ($this->gateway->credentials['key_secret'] ?? '');
        $signature = (string) $request->query('razorpay_signature', '');

        if ($secret === '' || $signature === '') {
            return new ReturnHint(signatureValid: false, looksPaid: false);
        }

        $payload = implode('|', [
            (string) $request->query('razorpay_payment_link_id'),
            (string) $request->query('razorpay_payment_link_reference_id'),
            (string) $request->query('razorpay_payment_link_status'),
            (string) $request->query('razorpay_payment_id'),
        ]);

        $valid = hash_equals(hash_hmac('sha256', $payload, $secret), $signature);

        return new ReturnHint(
            signatureValid: $valid,
            looksPaid: $valid && $request->query('razorpay_payment_link_status') === 'paid',
            paymentRef: $valid ? (string) $request->query('razorpay_payment_id') : null,
        );
    }

    public function fetchStatus(Order $order): array
    {
        if ($order->gateway_ref === null) {
            return ['paid' => false, 'failed' => false, 'payment_ref' => null, 'raw' => []];
        }

        $response = $this->client()->get(self::API.'/payment_links/'.$order->gateway_ref);

        if (! $response->successful()) {
            return ['paid' => false, 'failed' => false, 'payment_ref' => null, 'raw' => ['error' => $response->json()]];
        }

        $link = $response->json();
        $payments = $link['payments'] ?? [];

        return [
            'paid' => ($link['status'] ?? '') === 'paid',
            'failed' => in_array($link['status'] ?? '', ['cancelled', 'expired'], true),
            'payment_ref' => is_array($payments) && $payments !== [] ? ($payments[0]['payment_id'] ?? null) : null,
            'raw' => $link,
        ];
    }

    public function readWebhook(Request $request): WebhookEvent
    {
        $secret = (string) ($this->gateway->credentials['webhook_secret'] ?? '');

        if ($secret === '') {
            throw new PaymentFailed('No Razorpay webhook secret is configured.');
        }

        $signature = (string) $request->header('X-Razorpay-Signature');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new PaymentFailed('Razorpay webhook signature did not match.');
        }

        $payload = $request->json()->all();
        $type = (string) ($payload['event'] ?? '');
        $link = $payload['payload']['payment_link']['entity'] ?? [];
        $payment = $payload['payload']['payment']['entity'] ?? [];

        return new WebhookEvent(
            // Razorpay has no event id in the body, so one is derived from the
            // event, its subject and its timestamp — enough to spot a repeat.
            id: (string) ($request->header('X-Razorpay-Event-Id')
                ?: $type.':'.($link['id'] ?? $payment['id'] ?? '').':'.($payload['created_at'] ?? '')),
            type: $type,
            paid: in_array($type, ['payment_link.paid', 'order.paid', 'payment.captured'], true),
            failed: in_array($type, ['payment.failed', 'payment_link.expired', 'payment_link.cancelled'], true),
            gatewayRef: is_string($link['id'] ?? null) ? $link['id'] : null,
            paymentRef: is_string($payment['id'] ?? null) ? $payment['id'] : null,
            payload: $payload,
        );
    }

    private function client(): PendingRequest
    {
        $id = (string) ($this->gateway->credentials['key_id'] ?? '');
        $secret = (string) ($this->gateway->credentials['key_secret'] ?? '');

        if ($id === '' || $secret === '') {
            throw new PaymentFailed('Razorpay keys are not configured.');
        }

        return Http::withBasicAuth($id, $secret)->timeout(20)->asJson();
    }
}
