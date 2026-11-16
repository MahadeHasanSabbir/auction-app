<?php

namespace App\Services\Payments;

use App\Support\Money;

/** What a gateway needs to start a payment. */
final readonly class CheckoutRequest
{
    public function __construct(
        /** Our reference, unique per payment; the gateway sends it back. */
        public string $tranId,
        public Money $amount,
        public string $description,
        public string $customerName,
        public string $customerEmail,
        public CheckoutUrls $urls,
    ) {}
}
