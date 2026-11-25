<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A verified, active buyer by default.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Buyer,
            'company_id' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function systemOwner(): static
    {
        return $this->state(['role' => UserRole::SystemOwner, 'company_id' => null]);
    }

    public function platformStaff(): static
    {
        return $this->state(['role' => UserRole::PlatformStaff, 'company_id' => null]);
    }

    /** The owner of the given company, or of a new one. */
    public function companyOwner(Company|int|null $company = null): static
    {
        return $this->state([
            'role' => UserRole::CompanyOwner,
            'company_id' => $this->companyKey($company),
        ]);
    }

    /** A team member of the given company, or of a new one. */
    public function companyMember(Company|int|null $company = null): static
    {
        return $this->state([
            'role' => UserRole::CompanyMember,
            'company_id' => $this->companyKey($company),
        ]);
    }

    /** @return int|Factory<Company> */
    private function companyKey(Company|int|null $company): int|Factory
    {
        return match (true) {
            $company instanceof Company => $company->id,
            is_int($company) => $company,
            default => Company::factory(),
        };
    }
}
