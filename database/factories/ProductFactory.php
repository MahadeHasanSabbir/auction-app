<?php

namespace Database\Factories;

use App\Enums\ProductCondition;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'category_id' => Category::factory(),
            // A member of the same company.
            'created_by' => fn (array $attributes) => User::factory()->companyMember($attributes['company_id']),
            'name' => ucfirst(fake()->words(3, true)),
            'description' => fake()->paragraph(),
            'condition' => fake()->randomElement(ProductCondition::cases()),
        ];
    }
}
