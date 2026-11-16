<?php

namespace Database\Factories;

use App\Enums\NoticeAudience;
use App\Models\Notice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A published notice for everyone by default.
 *
 * @extends Factory<Notice>
 */
class NoticeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => ucfirst(fake()->words(4, true)),
            'body' => fake()->paragraph(),
            'audience' => NoticeAudience::All,
            'published_at' => now()->subHour(),
            'expires_at' => null,
            'created_by' => User::factory()->platformStaff(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['published_at' => null]);
    }

    public function expired(): static
    {
        return $this->state(['published_at' => now()->subDays(10), 'expires_at' => now()->subDay()]);
    }
}
