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

    /** The callback routes of one gateway (routes/payments.php). */
    public static function forGateway(string $gateway): self
    {
        return new self(
            success: route('payments.return', ['gateway' => $gateway]),
            fail: route('payments.return', ['gateway' => $gateway, 'outcome' => 'failed']),
            cancel: route('payments.return', ['gateway' => $gateway, 'outcome' => 'cancelled']),
            notify: route('payments.notify', ['gateway' => $gateway]),
        );
    }
}
