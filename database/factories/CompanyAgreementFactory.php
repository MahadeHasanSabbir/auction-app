<?php

namespace Database\Factories;

use App\Enums\CommissionBasis;
use App\Enums\FeeUseCase;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyAgreement>
 */
class CompanyAgreementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'use_case' => FeeUseCase::SaleCommission,
            'basis' => CommissionBasis::FinalPrice,
            'rate_bps' => 750,
            'fixed_amount' => 0,
            'effective_from' => now()->subDay(),
            'effective_until' => null,
            'created_by' => User::factory()->systemOwner(),
        ];
    }
}
