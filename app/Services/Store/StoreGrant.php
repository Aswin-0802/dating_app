<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Models\Plan;

/**
 * What Checkout::fulfil() needs to know when the order it is fulfilling came
 * from a store: the verified transaction (whose expiry is the calendar), the
 * plan the product maps to, and the period that product represents.
 */
final readonly class StoreGrant
{
    public function __construct(
        public StoreTransaction $transaction,
        public Plan $plan,
        public string $billingPeriod,
    ) {}
}
