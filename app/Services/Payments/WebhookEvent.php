<?php

declare(strict_types=1);

namespace App\Services\Payments;

/** A verified webhook, in terms the checkout flow understands. */
final readonly class WebhookEvent
{
    /**
     * @param  string  $id  the gateway's own event id, used to ignore repeats
     * @param  string|null  $gatewayRef  the checkout this event is about
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public bool $paid,
        public bool $failed,
        public ?string $gatewayRef,
        public ?string $paymentRef = null,
        public array $payload = [],
    ) {}
}
