<?php

namespace Database\Factories;

use App\Enums\FraudFlagKind;
use App\Enums\FraudFlagStatus;
use App\Models\Auction;
use App\Models\FraudFlag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FraudFlag> */
class FraudFlagFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => FraudFlagKind::SharedIp,
            'user_id' => User::factory(),
            'auction_id' => Auction::factory()->live(),
            'company_id' => fn (array $attributes) => Auction::query()->find($attributes['auction_id'])?->company_id,
            'ip_address' => fake()->ipv4(),
            'details' => [],
            'status' => FraudFlagStatus::Open,
        ];
    }
}
