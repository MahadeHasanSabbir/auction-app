<?php

namespace Database\Factories;

use App\Enums\StrikeSource;
use App\Models\Strike;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Strike> */
class StrikeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => StrikeSource::Manual,
            'reason' => fake()->sentence(),
            'expires_at' => now()->addDays(180),
        ];
    }
}
