<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word()).' plan';

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'price_monthly' => 250_000,
            'price_yearly' => 2_500_000,
            'max_users' => 5,
            'max_active_auctions' => null,
            'trial_days' => 0,
            'is_public' => true,
            'is_active' => true,
        ];
    }

    public function seats(int $maxUsers): static
    {
        return $this->state(['max_users' => $maxUsers]);
    }
}
