<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A pending invitation by default.
 *
 * @extends Factory<CompanyInvitation>
 */
class CompanyInvitationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(40)),
            'invited_by' => fn (array $attributes) => User::factory()->companyOwner($attributes['company_id']),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(['accepted_at' => now()]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
