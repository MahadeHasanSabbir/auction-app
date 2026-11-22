<?php

namespace Database\Factories;

use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedSearch> */
class SavedSearchFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $word = fake()->word();

        return [
            'user_id' => User::factory(),
            'name' => $word,
            'filters' => ['q' => $word],
            'alerts' => true,
        ];
    }
}
