<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Payments\CheckoutRequest;
use App\Services\Payments\CheckoutSession;
use App\Services\Payments\CheckoutUrls;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentGateways;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The company owner pays an open invoice at a gateway: the attempt gets a
 * fresh reference and the gateway says where to send the payer. PayInvoice
 * settles it once the gateway confirms.
 */
final class StartInvoicePayment
{
    public function __construct(private readonly PaymentGateways $gateways) {}

    /**
     * @throws ValidationException when the invoice or gateway is not acceptable
     * @throws PaymentGatewayException when the gateway refuses
     */
    public function handle(User $user, SubscriptionInvoice $invoice, string $gateway, CheckoutUrls $urls): CheckoutSession
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        Gate::forUser($user)->authorize('manageBilling', $company);

        if ($invoice->status !== InvoiceStatus::Open || ! $invoice->amount->isPositive()) {
            throw ValidationException::withMessages(['invoice' => __('This invoice can no longer be paid.')]);
        }

        if (! $this->gateways->isEnabled($gateway)) {
            throw ValidationException::withMessages(['gateway' => __('Choose one of the payment methods offered.')]);
        }

        $invoice->forceFill([
            'gateway' => $gateway,
            'tran_id' => 'INV'.Str::upper((string) Str::ulid()),
            'gateway_reference' => null,
        ])->save();

        $checkout = $this->gateways->get($gateway)->checkout(new CheckoutRequest(
            tranId: (string) $invoice->tran_id,
            amount: $invoice->amount,
            description: __('Plan invoice :number', ['number' => $invoice->number]),
            customerName: $user->name,
            customerEmail: $user->email,
            urls: $urls,
            customerPhone: $user->phone,
        ));

        if ($checkout->gatewayReference !== null) {
            $invoice->forceFill(['gateway_reference' => $checkout->gatewayReference])->save();
        }

        return $checkout;
    }
}
