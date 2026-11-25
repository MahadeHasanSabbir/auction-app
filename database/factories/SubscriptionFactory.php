<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active monthly subscription by default.
 *
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
            'price' => 250_000,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ];
    }

    public function trialing(int $days = 14): static
    {
        return $this->state([
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays($days),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($days),
        ]);
    }

    public function pastDue(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::PastDue,
            'current_period_start' => now()->subDays(33),
            'current_period_end' => now()->subDays(3),
            'grace_ends_at' => now()->addDays(4),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::Expired,
            'current_period_start' => now()->subDays(60),
            'current_period_end' => now()->subDays(30),
            'ended_at' => now()->subDays(23),
        ]);
    }
}
