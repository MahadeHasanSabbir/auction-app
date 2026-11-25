<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A verified, active company by default.
 *
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'email' => fake()->companyEmail(),
            'phone' => fake()->numerify('017########'),
            'address' => fake()->address(),
            'about' => fake()->paragraph(),
            'status' => CompanyStatus::Active,
            'verified_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => CompanyStatus::Pending, 'verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state([
            'status' => CompanyStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Suspended by the platform.',
        ]);
    }
}
