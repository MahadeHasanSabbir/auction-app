<?php

namespace App\Actions\Billing;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A new company chooses its plan (or a lapsed one comes back). A plan with a
 * trial starts the trial, once per company; a free plan starts at once; any
 * other plan waits for its first invoice to be paid.
 */
final class SubscribeToPlan
{
    public function __construct(private readonly IssueInvoice $invoices) {}

    /**
     * @return array{subscription: Subscription, invoice: SubscriptionInvoice|null}
     *
     * @throws ValidationException when the plan cannot be chosen
     */
    public function handle(User $user, Company $company, Plan $plan, BillingInterval $interval): array
    {
        Gate::forUser($user)->authorize('manageBilling', $company);

        $price = $plan->priceFor($interval);

        if (! $plan->is_active || ! $plan->is_public || $price === null) {
            throw ValidationException::withMessages(['plan' => __('This plan is not available.')]);
        }

        return DB::transaction(function () use ($company, $plan, $interval, $price): array {
            $subscription = Subscription::query()->where('company_id', $company->id)->lockForUpdate()->first();

            if ($subscription !== null && ! in_array($subscription->status, [SubscriptionStatus::Incomplete, SubscriptionStatus::Expired], true)) {
                throw ValidationException::withMessages(['plan' => __('Your company already has a plan. Change it from the billing page.')]);
            }

            $seatsUsed = $company->users()->count();

            if ($seatsUsed > $plan->max_users) {
                throw ValidationException::withMessages([
                    'plan' => __(':plan has :seats seats and your team has :used members.', ['plan' => $plan->name, 'seats' => $plan->max_users, 'used' => $seatsUsed]),
                ]);
            }

            $trial = $plan->trial_days > 0 && ! ($subscription?->hadTrial() ?? false);

            $subscription ??= new Subscription;
            $subscription->forceFill([
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'billing_interval' => $interval,
                'price' => $price,
                'pending_plan_id' => null,
                'pending_billing_interval' => null,
                'max_users_override' => null,
                'max_active_auctions_override' => null,
                'cancelled_at' => null,
                'ended_at' => null,
                'grace_ends_at' => null,
            ]);

            $now = now();
            $invoice = null;

            if ($trial) {
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Trialing,
                    'trial_ends_at' => $now->addDays($plan->trial_days),
                    'current_period_start' => $now,
                    'current_period_end' => $now->addDays($plan->trial_days),
                ])->save();
            } elseif ($price->isZero()) {
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Active,
                    'current_period_start' => $now,
                    'current_period_end' => $interval->endFrom($now),
                ])->save();
            } else {
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Incomplete,
                    'current_period_start' => null,
                    'current_period_end' => null,
                ])->save();

                $invoice = $this->invoices->handle($subscription, $plan, $interval, $price, $now, $interval->endFrom($now));
            }

            return ['subscription' => $subscription, 'invoice' => $invoice];
        });
    }
}
