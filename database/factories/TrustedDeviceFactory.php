<?php

namespace Database\Factories;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A browser that is still trusted.
 *
 * @extends Factory<TrustedDevice>
 */
class TrustedDeviceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(64)),
            'user_agent' => fake()->userAgent(),
            'ip_address' => fake()->ipv4(),
            'last_used_at' => now()->subDay(),
            'expires_at' => now()->addDays(20),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
