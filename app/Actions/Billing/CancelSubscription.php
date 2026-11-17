<?php

namespace App\Actions\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Stops a plan from renewing. The company keeps selling until the paid
 * period (or trial) ends; `resume` undoes it until then.
 */
final class CancelSubscription
{
    public function handle(User $user, Company $company, bool $cancel = true): Subscription
    {
        Gate::forUser($user)->authorize('manageBilling', $company);

        return DB::transaction(function () use ($company, $cancel): Subscription {
            $subscription = Subscription::query()->where('company_id', $company->id)->lockForUpdate()->firstOrFail();

            $live = in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true)
                && $subscription->current_period_end?->isFuture();

            if (! $live) {
                throw ValidationException::withMessages(['plan' => __('There is nothing to change on this plan.')]);
            }

            $subscription->forceFill([
                'cancelled_at' => $cancel ? now() : null,
                'pending_plan_id' => $cancel ? null : $subscription->pending_plan_id,
                'pending_billing_interval' => $cancel ? null : $subscription->pending_billing_interval,
            ])->save();

            return $subscription;
        });
    }
}
