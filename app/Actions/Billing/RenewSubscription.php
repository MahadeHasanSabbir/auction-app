<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves one subscription forward when its period (or trial) is over. Run by
 * `subscriptions:renew`; calling it again changes nothing.
 *
 * - Cancelled: expires at the end of the period.
 * - Otherwise the next period is invoiced and paid from the company wallet
 *   if it holds enough. If not, the subscription is past due: the company
 *   keeps selling for the grace period, the invoice stays open, and each run
 *   tries the wallet again. When the grace period ends it expires: no new
 *   listings, but running auctions and orders finish.
 * - A subscription still waiting for its first payment expires when that
 *   invoice's due date passes.
 *
 * Returns what happened: 'renewed', 'past_due', 'expired' or 'unchanged'.
 */
final class RenewSubscription
{
    public function __construct(
        private readonly IssueInvoice $invoices,
        private readonly PayInvoice $payments,
    ) {}

    public function handle(Subscription $subscription): string
    {
        return DB::transaction(function () use ($subscription): string {
            $subscription = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            return match ($subscription->status) {
                SubscriptionStatus::Incomplete => $this->abandonIfUnpaid($subscription),
                SubscriptionStatus::Trialing, SubscriptionStatus::Active => $this->renewIfOver($subscription),
                SubscriptionStatus::PastDue => $this->retryOrExpire($subscription),
                default => 'unchanged',
            };
        });
    }

    private function abandonIfUnpaid(Subscription $subscription): string
    {
        $due = $this->openInvoice($subscription)?->due_at;

        if ($due !== null && $due->isFuture()) {
            return 'unchanged';
        }

        return $this->expire($subscription);
    }

    private function renewIfOver(Subscription $subscription): string
    {
        $end = $subscription->current_period_end;

        if ($end === null || $end->isFuture()) {
            return 'unchanged';
        }

        if ($subscription->cancelled_at !== null) {
            return $this->expire($subscription);
        }

        $this->billNextPeriod($subscription);

        return $this->payOrGrace($subscription);
    }

    private function retryOrExpire(Subscription $subscription): string
    {
        if ($this->openInvoice($subscription) === null) {
            $this->billNextPeriod($subscription);
        }

        if ($this->payOrGrace($subscription) === 'renewed') {
            return 'renewed';
        }

        if ($subscription->grace_ends_at === null || $subscription->grace_ends_at->isPast()) {
            return $this->expire($subscription);
        }

        return 'unchanged';
    }

    /** Issues the invoice for the period after this one, at the scheduled plan if there is one. */
    private function billNextPeriod(Subscription $subscription): SubscriptionInvoice
    {
        /** @var Plan $plan */
        $plan = $subscription->pending_plan_id !== null
            ? Plan::query()->findOrFail($subscription->pending_plan_id)
            : Plan::query()->findOrFail($subscription->plan_id);
        $interval = $subscription->pending_billing_interval ?? $subscription->billing_interval;
        $scheduled = $subscription->pending_plan_id !== null;

        $price = $scheduled ? ($plan->priceFor($interval) ?? $subscription->price) : $subscription->price;
        $start = $subscription->current_period_end ?? now();

        return $this->invoices->handle($subscription, $plan, $interval, $price, $start, $interval->endFrom($start));
    }

    /** Pays the open invoice from the wallet; if that fails, starts or continues the grace period. */
    private function payOrGrace(Subscription $subscription): string
    {
        $invoice = $this->openInvoice($subscription);

        if ($invoice === null) {
            return 'unchanged';
        }

        try {
            $this->payments->fromWallet($invoice);

            return 'renewed';
        } catch (ValidationException) {
            // Not enough in the wallet; the owner can pay the invoice.
        }

        if ($subscription->status !== SubscriptionStatus::PastDue) {
            $subscription->forceFill([
                'status' => SubscriptionStatus::PastDue,
                'grace_ends_at' => now()->addDays((int) config('auction.billing.grace_days')),
            ])->save();
        }

        return 'past_due';
    }

    private function expire(Subscription $subscription): string
    {
        $subscription->forceFill([
            'status' => SubscriptionStatus::Expired,
            'ended_at' => now(),
            'grace_ends_at' => null,
        ])->save();

        // Coming back means choosing a plan again, which bills a fresh period.
        SubscriptionInvoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', InvoiceStatus::Open)
            ->update(['status' => InvoiceStatus::Void, 'tran_id' => null]);

        return 'expired';
    }

    private function openInvoice(Subscription $subscription): ?SubscriptionInvoice
    {
        return SubscriptionInvoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', InvoiceStatus::Open)
            ->latest('id')
            ->first();
    }
}
