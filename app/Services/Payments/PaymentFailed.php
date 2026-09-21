<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

/**
 * Something went wrong talking to a gateway.
 *
 * Carries two messages on purpose: one a member may see, and one for staff —
 * "Your card was declined" belongs on screen, "401 from Stripe: expired API
 * key" belongs in the log and nowhere near a member.
 */
class PaymentFailed extends RuntimeException
{
    public function __construct(
        string $internalMessage,
        public readonly string $memberMessage = 'We could not start that payment. Please try again in a moment.',
    ) {
        parent::__construct($internalMessage);
    }
}
