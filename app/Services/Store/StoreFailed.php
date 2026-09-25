<?php

declare(strict_types=1);

namespace App\Services\Store;

use RuntimeException;

/**
 * A store purchase could not be honoured.
 *
 * `$reason` is the machine-readable code the API returns, and decides the
 * status: receipt_invalid / product_unknown -> 422, receipt_owned_elsewhere
 * -> 409, store_unavailable -> 503, bad_signature -> 400 (webhooks only).
 * The message is for the log; `$memberMessage` is the only text a member sees.
 */
class StoreFailed extends RuntimeException
{
    public function __construct(
        string $internalMessage,
        public readonly string $reason = 'receipt_invalid',
        public readonly ?string $memberMessage = null,
    ) {
        parent::__construct($internalMessage);
    }
}
