<?php

namespace Database\Factories;

use App\Enums\FeeChargeType;
use App\Enums\FeeUseCase;
use App\Models\FeeRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A 1% service charge on deposits, for every user type, by default.
 *
 * @extends Factory<FeeRule>
 */
class FeeRuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'use_case' => FeeUseCase::Deposit,
            'charge_type' => FeeChargeType::Service,
            'user_type_id' => null,
            'gateway' => null,
            'percent_bps' => 100,
            'fixed_amount' => 0,
            'effective_from' => now()->subDay(),
            'effective_until' => null,
            'is_active' => true,
        ];
    }
}
