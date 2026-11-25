<?php

namespace Database\Factories;

use App\Enums\InvoiceKind;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A purchase receipt for the order's buyer.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => InvoiceKind::Purchase,
            'number' => 'PUR-'.now()->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'subject_type' => 'order',
            'subject_id' => Order::factory(),
            'user_id' => fn (array $attributes) => Order::query()->find($attributes['subject_id'])?->buyer_id,
            'billed_name' => fake()->name(),
            'billed_email' => fake()->safeEmail(),
            'currency' => (string) config('auction.currency'),
            'lines' => [['description' => 'Item', 'amount' => 100_000]],
            'total' => 100_000,
            'issued_at' => now(),
        ];
    }
}
