<?php

namespace App\Services\Payments;

/** A payment registered with the gateway: where to send the payer. */
final readonly class CheckoutSession
{
    public function __construct(
        public string $redirectUrl,
        /** The gateway's own id for the session, when it gives one up front. */
        public ?string $gatewayReference = null,
    ) {}
}
