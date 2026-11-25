<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\LedgerComponent;
use App\Enums\LedgerTransactionType;
use App\Enums\PaymentOutcome;
use App\Enums\SubscriptionStatus;
use App\Enums\SystemAccount;
use App\Models\LedgerTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Wallet;
use App\Services\Invoices\Invoices;
use App\Services\Ledger\InsufficientFundsException;
use App\Services\Ledger\Ledger;
use App\Services\Payments\InvalidCallbackException;
use App\Services\Payments\PaymentResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Settles a plan invoice, from the company wallet or from a gateway payment,
 * and then starts the period it pays for (and the plan it was issued for).
 *
 * Postings: the plan revenue account is credited the amount less the VAT
 * in it, which goes to tax payable; the debit is
 * the company wallet, or gateway clearing when a payer paid at the gateway.
 * Callers decide who may pay; this class only checks the invoice.
 */
final class PayInvoice
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Invoices $invoices,
    ) {}

    /**
     * @throws ValidationException when the invoice is not open or the wallet cannot cover it
     */
    public function fromWallet(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        return DB::transaction(function () use ($invoice): SubscriptionInvoice {
            $invoice = $this->lock($invoice->id);

            if ($invoice->status === InvoiceStatus::Paid) {
                return $invoice;
            }

            $this->assertOpen($invoice);

            $transaction = null;

            if ($invoice->amount->isPositive()) {
                try {
                    $transaction = $this->post($invoice, Wallet::query()->where('company_id', $invoice->company_id)->firstOrFail());
                } catch (InsufficientFundsException $e) {
                    throw ValidationException::withMessages([
                        'wallet' => __('The company wallet holds :available; this invoice is :amount.', [
                            'available' => $e->available->format(),
                            'amount' => $invoice->amount->format(),
                        ]),
                    ]);
                }
            }

            // A gateway attempt still out there now finds the reference gone and
            // is credited to the wallet instead.
            $invoice->forceFill(['tran_id' => null]);

            return $this->settle($invoice, $transaction);
        });
    }

    /**
     * What the gateway confirmed about an invoice payment. A failure leaves
     * the invoice open so it can be paid another way.
     *
     * @throws InvalidCallbackException when the result does not match an invoice
     */
    public function fromGateway(PaymentResult $result): SubscriptionInvoice
    {
        return DB::transaction(function () use ($result): SubscriptionInvoice {
            $found = (preg_match('/^INV(\d+)X/', $result->tranId, $m) === 1 ? (int) $m[1] : null)
                ?? SubscriptionInvoice::query()->where('tran_id', $result->tranId)->value('id')
                ?? throw new InvalidCallbackException("There is no invoice payment [{$result->tranId}].");

            $invoice = $this->lock($found);

            // A payment through an older attempt, or for an invoice that was
            // since voided, discounted or paid another way: the gateway has
            // confirmed the money, so it goes to the company wallet.
            if ($invoice->tran_id !== $result->tranId) {
                if ($result->outcome === PaymentOutcome::Succeeded) {
                    $this->creditStrayPayment($invoice, $result);
                }

                return $invoice;
            }

            if ($invoice->gateway !== $result->gateway) {
                throw new InvalidCallbackException("Invoice [{$invoice->number}] was not paid through {$result->gateway}.");
            }

            if ($result->outcome !== PaymentOutcome::Succeeded) {
                return $invoice;
            }

            // Still this attempt's reference and not open: this very payment
            // settled it already (paying from the wallet or voiding clears the reference).
            if ($invoice->status !== InvoiceStatus::Open) {
                return $invoice;
            }

            if (! $result->amount->equals($invoice->amount)) {
                throw new InvalidCallbackException(
                    "Invoice [{$invoice->number}] is for {$invoice->amount->toDecimal()} {$invoice->currency}; "
                    ."the gateway reports {$result->amount->toDecimal()} {$result->amount->currency}."
                );
            }

            $transaction = $this->post($invoice, $this->ledger->system(SystemAccount::GatewayClearing));
            $invoice->forceFill(['gateway_reference' => $result->gatewayReference ?? $invoice->gateway_reference]);

            return $this->settle($invoice, $transaction);
        });
    }

    /**
     * The subscription row first, then the invoice: the same order as
     * RenewSubscription, so a callback during the renewal run cannot deadlock.
     */
    private function lock(int $id): SubscriptionInvoice
    {
        $subscriptionId = SubscriptionInvoice::query()->whereKey($id)->value('subscription_id')
            ?? throw new InvalidCallbackException("There is no invoice [{$id}].");

        Subscription::query()->whereKey($subscriptionId)->lockForUpdate()->first();

        return SubscriptionInvoice::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Money the gateway confirmed for an invoice it can no longer pay goes to
     * the company wallet (once per gateway reference), where the owner can use
     * it for the open invoice or withdraw it.
     */
    private function creditStrayPayment(SubscriptionInvoice $invoice, PaymentResult $result): void
    {
        $this->ledger->transaction(LedgerTransactionType::Deposit)
            ->reference($invoice)
            ->idempotent("invoice-payment:{$result->tranId}")
            ->describe("Payment {$result->tranId} for plan invoice {$invoice->number}, credited to the wallet")
            ->debit($this->ledger->system(SystemAccount::GatewayClearing), $result->amount)
            ->credit(Wallet::query()->where('company_id', $invoice->company_id)->firstOrFail(), $result->amount)
            ->post();

        Log::warning('A plan invoice payment could not be applied and was credited to the company wallet.', [
            'invoice' => $invoice->number,
            'tran_id' => $result->tranId,
            'amount' => $result->amount->toDecimal(),
        ]);
    }

    private function assertOpen(SubscriptionInvoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Open) {
            throw ValidationException::withMessages(['invoice' => __('This invoice can no longer be paid.')]);
        }
    }

    private function post(SubscriptionInvoice $invoice, Wallet $payer): LedgerTransaction
    {
        return $this->ledger->transaction(LedgerTransactionType::SubscriptionPayment)
            ->reference($invoice)
            ->idempotent("invoice:{$invoice->id}")
            ->describe("Plan invoice {$invoice->number}")
            ->debit($payer, $invoice->amount)
            ->creditWithVat($this->ledger->system(SystemAccount::SubscriptionRevenue), $invoice->amount, LedgerComponent::Principal)
            ->post();
    }

    /** Marks the invoice paid and starts what it paid for. */
    private function settle(SubscriptionInvoice $invoice, ?LedgerTransaction $transaction): SubscriptionInvoice
    {
        $invoice->forceFill([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
            'ledger_transaction_id' => $transaction?->id,
        ])->save();

        if ($transaction !== null) {
            $this->invoices->subscription($invoice, $transaction);
        }

        $subscription = Subscription::query()->whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();
        $changed = $subscription->plan_id !== $invoice->plan_id || $subscription->billing_interval !== $invoice->billing_interval;

        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $invoice->period_start,
            'current_period_end' => $invoice->period_end,
            'grace_ends_at' => null,
            'ended_at' => null,
        ]);

        if ($changed) {
            $plan = Plan::query()->findOrFail($invoice->plan_id);

            // A plan switch takes the plan's limits and price; custom limits
            // were agreed for the old plan.
            $subscription->forceFill([
                'plan_id' => $plan->id,
                'billing_interval' => $invoice->billing_interval,
                'price' => $plan->priceFor($invoice->billing_interval) ?? $invoice->amount,
                'max_users_override' => null,
                'max_active_auctions_override' => null,
            ]);
        }

        if ($subscription->pending_plan_id === $invoice->plan_id) {
            $subscription->forceFill(['pending_plan_id' => null, 'pending_billing_interval' => null]);
        }

        $subscription->save();

        return $invoice;
    }
}
