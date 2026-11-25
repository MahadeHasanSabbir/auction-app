<?php

namespace App\Actions\Billing;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A company moves to another plan or billing interval.
 *
 * - During a trial the switch is immediate.
 * - A dearer plan (per month) is an upgrade: the unused part of the paid
 *   period is credited and the rest is invoiced; the plan changes when that
 *   invoice is paid (PayInvoice). A credit that covers it switches at once.
 * - A cheaper plan is a downgrade: it is scheduled for the end of the period.
 *   Choosing the current plan again cancels a scheduled downgrade.
 *
 * The result says which of 'switched', 'upgrade_due', 'scheduled' or
 * 'unscheduled' happened.
 */
final class ChangePlan
{
    public function __construct(private readonly IssueInvoice $invoices) {}

    /**
     * @return array{result: string, invoice: SubscriptionInvoice|null}
     *
     * @throws ValidationException when the change is not possible
     */
    public function handle(User $user, Company $company, Plan $plan, BillingInterval $interval): array
    {
        Gate::forUser($user)->authorize('manageBilling', $company);

        return DB::transaction(function () use ($company, $plan, $interval): array {
            $subscription = Subscription::query()->where('company_id', $company->id)->lockForUpdate()->first();

            if ($subscription === null || ! in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)) {
                throw ValidationException::withMessages(['plan' => __('Pay the open invoice or choose a plan first.')]);
            }

            $same = $plan->id === $subscription->plan_id && $interval === $subscription->billing_interval;

            if ($same) {
                if ($subscription->pending_plan_id === null) {
                    throw ValidationException::withMessages(['plan' => __('This is your current plan.')]);
                }

                $subscription->forceFill(['pending_plan_id' => null, 'pending_billing_interval' => null])->save();

                return ['result' => 'unscheduled', 'invoice' => null];
            }

            $price = $plan->priceFor($interval);
            $keepsCurrentPlan = $plan->id === $subscription->plan_id;

            if (! $keepsCurrentPlan && (! $plan->is_active || ! $plan->is_public)) {
                $price = null;
            }

            if ($price === null) {
                throw ValidationException::withMessages(['plan' => __('This plan is not available.')]);
            }

            $seatsUsed = $company->seatsUsed();

            if ($seatsUsed > $plan->max_users) {
                throw ValidationException::withMessages([
                    'plan' => __(':plan has :seats seats and your team uses :used. Remove members or revoke invitations first.', ['plan' => $plan->name, 'seats' => $plan->max_users, 'used' => $seatsUsed]),
                ]);
            }

            if ($subscription->status === SubscriptionStatus::Trialing) {
                $subscription->forceFill([
                    'plan_id' => $plan->id,
                    'billing_interval' => $interval,
                    'price' => $price,
                    'max_users_override' => null,
                    'max_active_auctions_override' => null,
                    'pending_plan_id' => null,
                    'pending_billing_interval' => null,
                ])->save();

                return ['result' => 'switched', 'invoice' => null];
            }

            $currentRate = intdiv($subscription->price->amount, $subscription->billing_interval->months());
            $newRate = (int) $plan->monthlyRate($interval);

            if ($newRate <= $currentRate) {
                $subscription->forceFill(['pending_plan_id' => $plan->id, 'pending_billing_interval' => $interval])->save();

                return ['result' => 'scheduled', 'invoice' => null];
            }

            $due = $price->minus($this->unusedCredit($subscription));

            if (! $due->isPositive()) {
                $subscription->forceFill([
                    'plan_id' => $plan->id,
                    'billing_interval' => $interval,
                    'price' => $price,
                    'max_users_override' => null,
                    'max_active_auctions_override' => null,
                    'pending_plan_id' => null,
                    'pending_billing_interval' => null,
                ])->save();

                return ['result' => 'switched', 'invoice' => null];
            }

            $now = now();
            $invoice = $this->invoices->handle($subscription, $plan, $interval, $due, $now, $interval->endFrom($now));

            return ['result' => 'upgrade_due', 'invoice' => $invoice];
        });
    }

    /** The part of the current period's price that has not been used yet. */
    private function unusedCredit(Subscription $subscription): Money
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        if ($start === null || $end === null || ! $end->isFuture()) {
            return $subscription->price->times(0);
        }

        $total = max(1, $end->getTimestamp() - $start->getTimestamp());
        $left = min($total, $end->getTimestamp() - now()->getTimestamp());

        return Money::minor(intdiv($subscription->price->amount * $left, $total), $subscription->price->currency);
    }
}
