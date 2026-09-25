<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Store\StoreDriver;
use App\Services\Store\StoreFailed;
use App\Services\Store\StoreNotification;
use App\Services\Store\StoreTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A store that answers from a table instead of from Apple or Google.
 *
 * What the tests exercise is the billing rules — idempotency, ownership,
 * never-downgrade, deleted accounts — and none of that depends on how a JWS
 * or a purchase token is verified. Jws itself is covered in tests/Unit.
 *
 * verify() looks the token up; readNotification() accepts a JSON body
 * {id, type, token?, external_ref?} with bearer token "valid".
 */
final class FakeStoreDriver implements StoreDriver
{
    /** @var array<string, StoreTransaction> token => what the store says about it */
    public array $transactions = [];

    /** @var array<int, string> transaction ids acknowledged */
    public array $acknowledged = [];

    public function __construct(private readonly string $store) {}

    public function slug(): string
    {
        return $this->store;
    }

    public function verify(string $token): StoreTransaction
    {
        return $this->transactions[$token]
            ?? throw new StoreFailed("The fake {$this->store} store does not know token {$token}.", 'receipt_invalid', 'That purchase could not be found.');
    }

    public function readNotification(Request $request): StoreNotification
    {
        if ($request->bearerToken() !== 'valid') {
            throw new StoreFailed('Fake signature check failed.', 'bad_signature');
        }

        $body = $request->json()->all();
        $transaction = isset($body['token']) ? $this->verify((string) $body['token']) : null;

        return new StoreNotification(
            id: (string) $body['id'],
            store: $this->store,
            type: (string) ($body['type'] ?? 'DID_RENEW'),
            subtype: null,
            externalRef: $transaction?->originalTransactionId ?? ($body['external_ref'] ?? null),
            transaction: $transaction,
            raw: $body,
        );
    }

    public function acknowledge(StoreTransaction $transaction): void
    {
        $this->acknowledged[] = $transaction->transactionId;
    }

    public function check(): array
    {
        return ['ok' => true];
    }

    /** A transaction with sensible defaults; override what the test is about. */
    public function transaction(string $token, array $overrides = []): StoreTransaction
    {
        $values = $overrides + [
            'store' => $this->store,
            'originalTransactionId' => 'orig-1',
            'transactionId' => 'txn-1',
            'productId' => 'plus_monthly',
            'environment' => 'production',
            'state' => 'active',
            'ownership' => 'purchased',
            'purchasedAt' => Carbon::now(),
            'expiresAt' => Carbon::now()->addMonth(),
            'autoRenewing' => true,
            'appAccountToken' => null,
            'amount' => 12.99,
            'currency' => 'USD',
            'raw' => ['token' => $token],
        ];

        return $this->transactions[$token] = new StoreTransaction(...$values);
    }
}
