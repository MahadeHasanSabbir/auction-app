<?php

namespace Database\Factories;

use App\Enums\BidType;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bid>
 */
class BidFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'auction_id' => Auction::factory()->live(),
            'user_id' => User::factory(),
            'amount' => 105_000,
            'type' => BidType::Manual,
            'seq' => 1,
        ];
    }
}
