<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * What every payment gateway must be able to do.
 *
 * Four operations, because that is all a one-off payment needs: send the
 * member somewhere to pay, read what came back, ask the gateway directly, and
 * verify a webhook. Everything gateway-specific — amounts in paise, form
 * encoding, signature schemes — stays behind this line, so the checkout flow
 * is written once and a third gateway is a new class rather than an `if`.
 */
interface PaymentDriver
{
    /** The provider's slug, matching the payment_gateways table. */
    public function slug(): string;

    /** Currencies this gateway can actually charge in. */
    public function supports(string $currency): bool;

    /**
     * Create the payment at the gateway and return where to send the member.
     *
     * @return array{redirect_url: string, gateway_ref: string}
     *
     * @throws PaymentFailed
     */
    public function startCheckout(Order $order, string $returnUrl, string $cancelUrl): array;

    /**
     * Read the gateway's signed return, if it sends one.
     *
     * Only ever a hint: the member's browser is not a trustworthy source of
     * "this was paid", so the caller still confirms with fetchStatus().
     */
    public function readReturn(Request $request, Order $order): ReturnHint;

    /**
     * Ask the gateway what actually happened to this order.
     *
     * @return array{paid: bool, failed: bool, payment_ref: ?string, raw: array<string, mixed>}
     */
    public function fetchStatus(Order $order): array;

    /**
     * Verify a webhook's signature and describe it.
     *
     * @throws PaymentFailed when the signature does not match
     */
    public function readWebhook(Request $request): WebhookEvent;
}
