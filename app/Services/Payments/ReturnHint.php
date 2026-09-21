<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * What the gateway told the browser on the way back.
 *
 * `signatureValid` is false both when a signature fails and when the gateway
 * sends none, because neither is proof of payment — it only decides whether
 * the member sees "we are checking" or "that link looks wrong".
 */
final readonly class ReturnHint
{
    public function __construct(
        public bool $signatureValid,
        public bool $looksPaid,
        public ?string $paymentRef = null,
    ) {}
}
