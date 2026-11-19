<?php

namespace Database\Factories;

use App\Enums\LoginMethod;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoginEvent> */
class LoginEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fake()->safeEmail(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
            'method' => LoginMethod::Code,
            'succeeded' => true,
            'created_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(['method' => LoginMethod::Password, 'succeeded' => false]);
    }
}
