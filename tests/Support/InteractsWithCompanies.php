<?php

namespace Tests\Support;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;

/** Helpers for tests about seller companies, their plans and their team. */
trait InteractsWithCompanies
{
    /**
     * A paid plan: BDT 1,000 a month, BDT 10,000 a year, three seats.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function plan(array $attributes = []): Plan
    {
        return Plan::factory()->create($attributes + [
            'price_monthly' => 100_000,
            'price_yearly' => 1_000_000,
            'max_users' => 3,
            'trial_days' => 0,
        ]);
    }

    /**
     * A company with its owner and wallet, subscribed to the plan if given.
     *
     * @return array{0: Company, 1: User, 2: Wallet}
     */
    protected function company(?Plan $plan = null, SubscriptionStatus $status = SubscriptionStatus::Active): array
    {
        $company = Company::factory()->create();
        $owner = User::factory()->companyOwner($company)->create();
        $wallet = Wallet::factory()->forCompany($company)->create();

        if ($plan !== null) {
            Subscription::factory()->create([
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'status' => $status,
                'billing_interval' => BillingInterval::Monthly,
                'price' => $plan->price_monthly,
            ]);
        }

        return [$company, $owner, $wallet];
    }
}
