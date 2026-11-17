<?php

namespace App\Services\Bidding;

use App\Models\Auction;
use App\Models\AutoBid;
use App\Support\Money;

/** An auction's bidding state, as ProxyResolver needs it. */
final readonly class BidState
{
    public function __construct(
        public Money $starting,
        public Money $increment,
        public ?Money $reserve,
        public Money $price,
        public int $bidCount,
        public ?int $leaderId,
        // The leader's automatic maximum; null when the leader bid by hand.
        public ?Money $leaderAutoMax = null,
        public bool $leaderAuto = false,
    ) {}

    public static function of(Auction $auction, ?AutoBid $leaderAutoBid): self
    {
        $auto = $leaderAutoBid !== null && $leaderAutoBid->is_active && $leaderAutoBid->user_id === $auction->leader_id;

        return new self(
            starting: $auction->starting_price,
            increment: $auction->min_increment,
            reserve: $auction->reserve_price,
            price: $auction->current_price,
            bidCount: $auction->bid_count,
            leaderId: $auction->leader_id,
            leaderAutoMax: $auto ? $leaderAutoBid->max_amount : null,
            leaderAuto: $auto,
        );
    }

    public function minimumNext(): Money
    {
        return $this->bidCount === 0 ? $this->starting : $this->price->plus($this->increment);
    }

    /** What the leader would pay at most: their automatic maximum, or their bid. */
    public function leaderMax(): Money
    {
        return $this->leaderAuto && $this->leaderAutoMax !== null ? $this->leaderAutoMax : $this->price;
    }
}
