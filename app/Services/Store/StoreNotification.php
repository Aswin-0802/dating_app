<?php

declare(strict_types=1);

namespace App\Services\Store;

/**
 * A verified server notification from a store, in neutral terms.
 *
 * Apple's carry the transaction they are about (signed, so it is verified
 * and set here). Google's only name the purchase token, so `$transaction`
 * is null and the handler fetches the current state from the Play API —
 * the notification is a hint, never the evidence.
 */
final readonly class StoreNotification
{
    /**
     * @param  string  $id  the store's own id for this delivery — Apple notificationUUID, Pub/Sub messageId — used to ignore repeats
     * @param  string|null  $externalRef  the subscription this is about (originalTransactionId / purchase token); null for test pings
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $store,
        public string $type,
        public ?string $subtype,
        public ?string $externalRef,
        public ?StoreTransaction $transaction = null,
        public array $raw = [],
    ) {}
}
