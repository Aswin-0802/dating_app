<?php

declare(strict_types=1);

namespace App\Services\Store;

use Illuminate\Support\Carbon;

/**
 * A subscription transaction as the STORE describes it, after verification.
 *
 * Built only from what Apple or Google signed or answered; never from the
 * client's own claims. Everything the billing layer needs is here in store-
 * neutral terms, so StorePurchases does not know which store it is talking to.
 */
final readonly class StoreTransaction
{
    /**
     * @param  string  $store  'apple' | 'google'
     * @param  string  $originalTransactionId  Apple's originalTransactionId or Google's purchase token — the subscription's identity
     * @param  string  $transactionId  Apple's transactionId or Google's latestOrderId — one per renewal, the fulfilment dedup key
     * @param  string  $environment  'production' | 'sandbox'
     * @param  string  $state  'active' | 'grace' | 'hold' | 'expired' | 'revoked' | 'pending' | 'unknown'
     * @param  string  $ownership  'purchased' | 'family_shared'
     * @param  string|null  $appAccountToken  the member uuid the app attached at purchase time, if any
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $store,
        public string $originalTransactionId,
        public string $transactionId,
        public string $productId,
        public string $environment,
        public string $state,
        public string $ownership,
        public ?Carbon $purchasedAt,
        public ?Carbon $expiresAt,
        public ?bool $autoRenewing,
        public ?string $appAccountToken,
        public ?float $amount,
        public ?string $currency,
        public array $raw = [],
    ) {}

    /** Whether the store says this member is entitled to the plan right now. */
    public function isEntitled(): bool
    {
        return in_array($this->state, ['active', 'grace'], true)
            && $this->expiresAt !== null
            && $this->expiresAt->isFuture();
    }

    public function isFamilyShared(): bool
    {
        return $this->ownership === 'family_shared';
    }
}
