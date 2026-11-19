<?php

namespace Database\Factories;

use App\Enums\BlacklistType;
use App\Models\BlacklistEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BlacklistEntry> */
class BlacklistEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => BlacklistType::Email,
            'value' => fake()->unique()->safeEmail(),
            'reason' => fake()->sentence(),
            'created_by' => User::factory()->platformStaff(),
        ];
    }
}
