<?php

declare(strict_types=1);

namespace App\Services\Store;

use Illuminate\Http\Request;

/**
 * What a store must be able to do, mirroring PaymentDriver for the web
 * gateways. Three operations: tell us the truth about a transaction, verify
 * and describe a notification, and confirm receipt to the store where it
 * asks for that. Signature schemes, hosts and field names stay behind here.
 */
interface StoreDriver
{
    /** 'apple' | 'google', matching the payment_gateways row. */
    public function slug(): string;

    /**
     * Verify a client-supplied token with the store and describe the
     * subscription's CURRENT state. The token is an identifier, not evidence:
     * the answer comes from the store, never from decoding the client's blob.
     *
     * @param  string  $token  Apple: the signed transaction JWS. Google: the purchase token.
     *
     * @throws StoreFailed reason receipt_invalid | store_unavailable
     */
    public function verify(string $token): StoreTransaction;

    /**
     * Verify a server notification's signature and describe it.
     *
     * @throws StoreFailed reason bad_signature
     */
    public function readNotification(Request $request): StoreNotification;

    /**
     * Tell the store the purchase was honoured, where the store requires it.
     * Google refunds an unacknowledged purchase after three days; Apple has no
     * equivalent, so the Apple driver does nothing here.
     *
     * @throws StoreFailed reason store_unavailable
     */
    public function acknowledge(StoreTransaction $transaction): void;

    /**
     * A dry run against the store that proves the credentials without
     * touching any member — for platform:preflight.
     *
     * @return array{ok: bool, error?: string}
     */
    public function check(): array;
}
