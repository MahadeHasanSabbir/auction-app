<?php

namespace App\Services\Payments;

/** Where the gateway sends the payer back to, and where it notifies us. */
final readonly class CheckoutUrls
{
    public function __construct(
        public string $success,
        public string $fail,
        public string $cancel,
        /** Server-to-server notification (SSLCommerz IPN); Stripe uses its webhook instead. */
        public ?string $notify = null,
    ) {}
}
