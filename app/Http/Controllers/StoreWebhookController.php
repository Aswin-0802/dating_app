<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Store\StoreDrivers;
use App\Services\Store\StoreFailed;
use App\Services\Store\StorePurchases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where the app stores tell us a subscription renewed, lapsed or was
 * refunded. Same contract as the payment webhooks: 2xx for anything
 * understood, 400 for a signature that does not verify (never retried), 500
 * for anything thrown (retried — Apple up to five times over three days,
 * Pub/Sub until acknowledged).
 */
class StoreWebhookController extends Controller
{
    public function __construct(
        private readonly StoreDrivers $drivers,
        private readonly StorePurchases $purchases,
    ) {}

    public function __invoke(Request $request, string $store): JsonResponse
    {
        $driver = $this->drivers->for($store);

        if ($driver === null) {
            return response()->json(['message' => 'That store is not enabled.'], 404);
        }

        try {
            $notification = $driver->readNotification($request);
        } catch (StoreFailed $e) {
            Log::warning("Rejected a {$store} store notification: {$e->getMessage()}");

            return response()->json(['message' => 'Signature check failed.'], 400);
        }

        try {
            $this->purchases->handleNotification($store, $notification);
        } catch (Throwable $e) {
            report($e);

            // 500 on purpose: the store retries, and the event's processed_at
            // is still null so the retry does the work.
            return response()->json(['message' => 'Could not process the notification.'], 500);
        }

        return response()->json(['message' => 'ok']);
    }
}
