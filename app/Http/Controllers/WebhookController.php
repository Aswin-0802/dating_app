<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PaymentGateway;
use App\Services\Payments\Checkout;
use App\Services\Payments\PaymentFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where the gateways tell us a payment happened.
 *
 * This is the only word we trust about money. The browser can be closed, lie
 * or never come back; the webhook is signed by the gateway and retried until
 * it is acknowledged.
 *
 * Answers quickly and with a 2xx for anything it has understood — both
 * gateways treat a slow or non-2xx reply as a failure and queue a retry, and
 * Razorpay allows only five seconds.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly Checkout $checkout) {}

    public function __invoke(Request $request, string $gateway): JsonResponse
    {
        $record = PaymentGateway::query()->where('slug', $gateway)->first();
        $driver = $record === null ? null : $this->checkout->driverFor($record);

        if ($driver === null) {
            return response()->json(['message' => 'Unknown gateway.'], 404);
        }

        try {
            $event = $driver->readWebhook($request);
        } catch (PaymentFailed $e) {
            // A bad signature is either a misconfigured secret or somebody
            // trying it on. Neither should be retried, and neither is a 500.
            Log::warning("Rejected a {$gateway} webhook: {$e->getMessage()}");

            return response()->json(['message' => 'Signature check failed.'], 400);
        }

        try {
            $this->checkout->handleWebhook($gateway, $event);
        } catch (Throwable $e) {
            report($e);

            // 500 on purpose: the gateway will retry, and this order still
            // needs fulfilling.
            return response()->json(['message' => 'Could not process the event.'], 500);
        }

        return response()->json(['message' => 'ok']);
    }
}
