<?php

namespace Database\Factories;

use App\Enums\CouponDiscountType;
use App\Enums\CouponTarget;
use App\Models\Company;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Coupon> */
class CouponFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'C'.strtoupper(fake()->unique()->bothify('???###')),
            'company_id' => null,
            'target' => CouponTarget::PlanInvoice,
            'discount_type' => CouponDiscountType::Percent,
            'value' => 1_000,
            'per_user_limit' => 1,
            'is_active' => true,
        ];
    }

    /** A fixed amount off (minor units). */
    public function fixed(int $amount): static
    {
        return $this->state(['discount_type' => CouponDiscountType::Fixed, 'value' => $amount]);
    }

    public function featured(): static
    {
        return $this->state(['target' => CouponTarget::FeaturedFee]);
    }

    /** Wallet bonus credit of $amount minor units. */
    public function bonus(int $amount = 50_000): static
    {
        return $this->state(['target' => CouponTarget::WalletBonus, 'discount_type' => CouponDiscountType::Fixed, 'value' => $amount]);
    }

    /** A seller's coupon for its orders. */
    public function forCompany(?Company $company = null): static
    {
        return $this->state(['target' => CouponTarget::Order, 'company_id' => $company ?? Company::factory()]);
    }
}
