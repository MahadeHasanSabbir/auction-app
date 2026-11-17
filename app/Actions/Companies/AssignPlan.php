<?php

namespace App\Actions\Companies;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
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
 * The system owner puts a company on a plan by hand: any plan (including
 * non-public ones), at an agreed price and with custom limits if wanted. The
 * subscription is active from now and open invoices are voided.
 */
final class AssignPlan
{
    use LogsAdminActions;

    /**
     * @param  Money|null  $price  per period; null uses the plan's price
     *
     * @throws ValidationException when the plan has no price for the interval or the team does not fit
     */
    public function handle(
        User $owner,
        Company $company,
        Plan $plan,
        BillingInterval $interval,
        ?Money $price = null,
        ?int $maxUsers = null,
        ?int $maxActiveAuctions = null,
    ): Subscription {
        Gate::forUser($owner)->authorize('update', $company);

        $price ??= $plan->priceFor($interval);

        if ($price === null) {
            throw ValidationException::withMessages(['plan' => __('This plan has no price for that billing interval.')]);
        }

        $seats = $maxUsers ?? $plan->max_users;
        $used = $company->seatsUsed();

        if ($used > $seats) {
            throw ValidationException::withMessages(['max_users' => __('The team uses :used seats; the limit cannot be below that.', ['used' => $used])]);
        }

        return DB::transaction(function () use ($owner, $company, $plan, $interval, $price, $maxUsers, $maxActiveAuctions): Subscription {
            $subscription = Subscription::query()->where('company_id', $company->id)->lockForUpdate()->first() ?? new Subscription;
            $now = now();

            $subscription->forceFill([
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'billing_interval' => $interval,
                'price' => $price,
                'max_users_override' => $maxUsers,
                'max_active_auctions_override' => $maxActiveAuctions,
                'pending_plan_id' => null,
                'pending_billing_interval' => null,
                'current_period_start' => $now,
                'current_period_end' => $interval->endFrom($now),
                'grace_ends_at' => null,
                'cancelled_at' => null,
                'ended_at' => null,
            ])->save();

            SubscriptionInvoice::query()
                ->where('subscription_id', $subscription->id)
                ->where('status', InvoiceStatus::Open)
                ->update(['status' => InvoiceStatus::Void, 'tran_id' => null]);

            $this->record($owner, $company, 'plan_assigned', [
                'plan' => $plan->slug,
                'interval' => $interval->value,
                'price' => $price->amount,
                'max_users' => $maxUsers,
                'max_active_auctions' => $maxActiveAuctions,
            ]);

            return $subscription;
        });
    }
}
