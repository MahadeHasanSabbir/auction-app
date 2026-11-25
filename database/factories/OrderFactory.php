<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Auction;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An order in escrow for a new sold auction by default. Creates no ledger
 * entries; tests that care about money post those themselves.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'number' => 'ORD-'.fake()->unique()->numerify('##########'),
            'auction_id' => Auction::factory()->sold(),
            // Buyer, company and amount follow the auction.
            'buyer_id' => fn (array $attributes) => Auction::query()->findOrFail($attributes['auction_id'])->winner_id,
            'company_id' => fn (array $attributes) => Auction::query()->findOrFail($attributes['auction_id'])->company_id,
            'amount' => fn (array $attributes) => Auction::query()->findOrFail($attributes['auction_id'])->final_price,
            'commission' => 0,
            'seller_payout' => fn (array $attributes) => $attributes['amount'],
            'status' => OrderStatus::InEscrow,
            'ship_by_at' => now()->addDays(config('auction.orders.ship_by_days')),
        ];
    }
}
