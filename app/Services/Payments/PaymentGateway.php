<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;

/**
 * A payment provider the payer is sent to (SSLCommerz, Stripe, the fake one
 * for local work and tests).
 *
 * The flow: checkout() registers the payment and says where to send the
 * payer; the gateway later calls back or posts a webhook; verify() turns
 * that request into a PaymentResult it has confirmed with the gateway (a
 * validation API call or a signature check). Nothing a payer's browser sends
 * is trusted until verify() has confirmed it.
 */
interface PaymentGateway
{
    /** The name stored on deposits and used in fee rules, for example "sslcommerz". */
    public function name(): string;

    /** @throws PaymentGatewayException when the gateway refuses or cannot be reached */
    public function checkout(CheckoutRequest $request): CheckoutSession;

    /** @throws InvalidCallbackException when the request cannot be confirmed */
    public function verify(Request $request): PaymentResult;
}
