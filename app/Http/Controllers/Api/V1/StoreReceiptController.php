<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MeResource;
use App\Services\Store\StoreFailed;
use App\Services\Store\StorePurchases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * POST /me/premium/receipt — the app says "I bought this in the store".
 *
 * The transaction the app sends is an identifier, never evidence; the store
 * is asked and its answer decides. The reply is a fresh MeResource, so the
 * app simply replaces what it knows about the member. Replaying the same
 * transaction is a 200 that changes nothing.
 *
 * See dating_app_mobile/docs/premium-receipt-contract.md §1.
 */
class StoreReceiptController extends Controller
{
    public function store(Request $request, StorePurchases $purchases): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'product_id' => ['required', 'string', 'max:120'],
            // iOS: the StoreKit 2 signed transaction (a JWS, a few KB).
            // Android: the Play Billing purchase token.
            'transaction' => ['required', 'string', 'max:20000'],
        ]);

        $store = $data['platform'] === 'ios' ? 'apple' : 'google';
        $member = $request->user();

        try {
            $purchases->redeem($member, $store, $data['product_id'], $data['transaction']);
        } catch (StoreFailed $e) {
            Log::info("Receipt from member {$member->uuid} refused ({$e->reason}): {$e->getMessage()}");

            [$status, $code, $headers] = match ($e->reason) {
                'receipt_owned_elsewhere' => [409, 'receipt_owned_elsewhere', []],
                'store_unavailable' => [503, 'store_unavailable', ['Retry-After' => '30']],
                'product_unknown' => [422, 'product_unknown', []],
                default => [422, 'receipt_invalid', []],
            };

            return response()->json([
                'message' => $e->memberMessage ?? 'That purchase could not be verified.',
                'code' => $code,
            ], $status, $headers);
        }

        return response()->json([
            'data' => new MeResource($member->fresh(['profile', 'preferences', 'photos', 'interests', 'city.country'])),
        ]);
    }
}
