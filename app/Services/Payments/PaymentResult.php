<?php

namespace App\Services\Payments;

use App\Enums\PaymentOutcome;
use App\Support\Money;

/** A payment as confirmed by the gateway. */
final readonly class PaymentResult
{
    /** @param  array<string, mixed>  $payload  what the gateway sent, kept for support */
    public function __construct(
        public string $gateway,
        public string $tranId,
        public PaymentOutcome $outcome,
        public Money $amount,
        public ?string $gatewayReference = null,
        public array $payload = [],
    ) {}
}
