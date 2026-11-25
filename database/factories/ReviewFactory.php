<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'company_id' => fn (array $attributes) => Order::query()->findOrFail($attributes['order_id'])->company_id,
            'buyer_id' => fn (array $attributes) => Order::query()->findOrFail($attributes['order_id'])->buyer_id,
            'rating' => fake()->numberBetween(3, 5),
            'comment' => fake()->sentence(),
            'is_visible' => true,
        ];
    }
}
