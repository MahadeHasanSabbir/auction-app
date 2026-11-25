<?php

namespace Database\Factories;

use App\Enums\AccountKind;
use App\Models\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserType>
 */
class UserTypeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'account_kind' => AccountKind::Buyer,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function seller(): static
    {
        return $this->state(['account_kind' => AccountKind::Seller]);
    }
}
