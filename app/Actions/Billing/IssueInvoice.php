<?php

namespace App\Actions\Billing;

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Bills one period of a plan. A subscription owes one thing at a time: asking
 * again for the same bill returns the open invoice, and a bill for something
 * else voids the old one.
 */
final class IssueInvoice
{
    public function handle(
        Subscription $subscription,
        Plan $plan,
        BillingInterval $interval,
        Money $amount,
        CarbonInterface $start,
        CarbonInterface $end,
    ): SubscriptionInvoice {
        return DB::transaction(function () use ($subscription, $plan, $interval, $amount, $start, $end): SubscriptionInvoice {
            $open = SubscriptionInvoice::query()
                ->where('subscription_id', $subscription->id)
                ->where('status', InvoiceStatus::Open)
                ->lockForUpdate()
                ->get();

            $same = $open->first(fn (SubscriptionInvoice $invoice) => $invoice->plan_id === $plan->id
                && $invoice->billing_interval === $interval
                && $invoice->amount->equals($amount)
                && $invoice->period_start->equalTo($start));

            if ($same !== null) {
                return $same;
            }

            foreach ($open as $stale) {
                $stale->forceFill(['status' => InvoiceStatus::Void, 'tran_id' => null])->save();
            }

            $invoice = new SubscriptionInvoice;
            $invoice->forceFill([
                'subscription_id' => $subscription->id,
                'company_id' => $subscription->company_id,
                'plan_id' => $plan->id,
                'amount' => $amount,
                'currency' => $amount->currency,
                'billing_interval' => $interval,
                'status' => InvoiceStatus::Open,
                'period_start' => $start,
                'period_end' => $end,
                'due_at' => now()->addDays((int) config('auction.billing.invoice_due_days')),
            ])->save();

            $invoice->forceFill(['number' => 'INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)])->save();

            return $invoice;
        });
    }
}
