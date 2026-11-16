<?php

namespace Database\Factories;

use App\Enums\SystemAccount;
use App\Enums\WalletType;
use App\Models\Company;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An empty buyer wallet by default. Balances are never set here: money gets
 * into a wallet only through ledger entries.
 *
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => WalletType::Buyer,
            'user_id' => User::factory(),
            'company_id' => null,
            'system_account' => null,
            'currency' => config('auction.currency'),
        ];
    }

    public function forCompany(Company|int|null $company = null): static
    {
        return $this->state([
            'type' => WalletType::Company,
            'user_id' => null,
            'company_id' => $company instanceof Company ? $company->id : ($company ?? Company::factory()),
        ]);
    }

    public function system(SystemAccount $account): static
    {
        return $this->state([
            'type' => WalletType::System,
            'user_id' => null,
            'system_account' => $account,
        ]);
    }
}
