<?php

namespace Database\Factories;

use App\Enums\AuctionCloseReason;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft by default; one state per status. The states set the columns the
 * real actions would set, but create no bids, holds or orders.
 *
 * @extends Factory<Auction>
 */
class AuctionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            // The product and its creator belong to the same company.
            'product_id' => fn (array $attributes) => Product::factory()->state(['company_id' => $attributes['company_id']]),
            'created_by' => fn (array $attributes) => User::factory()->companyMember($attributes['company_id']),
            'title' => ucfirst(fake()->words(4, true)),
            'status' => AuctionStatus::Draft,
            'starting_price' => 100_000,
            'reserve_price' => null,
            'buy_now_price' => null,
            'min_increment' => 5_000,
            'current_price' => fn (array $attributes) => $attributes['starting_price'],
            'bid_count' => 0,
            'leader_id' => null,
            'winner_id' => null,
            'proposed_starts_at' => now()->addDay(),
            'proposed_ends_at' => now()->addDays(3),
        ];
    }

    public function pendingReview(): static
    {
        return $this->state([
            'status' => AuctionStatus::PendingReview,
            'submitted_at' => now()->subHour(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status' => AuctionStatus::Rejected,
            'submitted_at' => now()->subDay(),
            'reviewed_by' => User::factory()->platformStaff(),
            'reviewed_at' => now()->subHours(2),
            'rejection_reason' => 'The photos do not show the item clearly.',
        ]);
    }

    public function scheduled(): static
    {
        return $this->approved()->state([
            'status' => AuctionStatus::Scheduled,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(3),
            'original_ends_at' => now()->addDays(3),
        ]);
    }

    public function live(): static
    {
        return $this->approved()->state([
            'status' => AuctionStatus::Live,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'original_ends_at' => now()->addHours(2),
        ]);
    }

    /** Closed with a winner; leader and winner are the same new buyer. */
    public function sold(): static
    {
        return $this->ended()->state([
            'status' => AuctionStatus::Sold,
            'close_reason' => AuctionCloseReason::Sold,
            'current_price' => 150_000,
            'final_price' => 150_000,
            'bid_count' => 1,
            'reserve_met' => true,
            'leader_id' => User::factory(),
            'winner_id' => fn (array $attributes) => $attributes['leader_id'],
        ]);
    }

    public function unsold(): static
    {
        return $this->ended()->state([
            'status' => AuctionStatus::Unsold,
            'close_reason' => AuctionCloseReason::NoBids,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => AuctionStatus::Cancelled,
            'close_reason' => AuctionCloseReason::CancelledBySeller,
            'closed_at' => now()->subHour(),
        ]);
    }

    /** What the platform sets when it approves an auction. */
    private function approved(): static
    {
        return $this->state([
            'submitted_at' => now()->subDays(4),
            'reviewed_by' => User::factory()->platformStaff(),
            'reviewed_at' => now()->subDays(4),
            'anti_snipe_window_seconds' => config('auction.bidding.anti_snipe_window_seconds'),
            'anti_snipe_extend_seconds' => config('auction.bidding.anti_snipe_extend_seconds'),
            'anti_snipe_max_extensions' => config('auction.bidding.anti_snipe_max_extensions'),
        ]);
    }

    private function ended(): static
    {
        return $this->approved()->state([
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDay(),
            'original_ends_at' => now()->subDay(),
            'closed_at' => now()->subDay(),
        ]);
    }
}
