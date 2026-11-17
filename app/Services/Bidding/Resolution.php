<?php

namespace App\Services\Bidding;

use App\Support\Money;

/** The outcome of one bid: the rows to write and the auction's new state. */
final readonly class Resolution
{
    /** @param  list<ResolvedBid>  $bids */
    public function __construct(
        public array $bids,
        public Money $price,
        public int $leaderId,
        public Money $leaderMax,
        public bool $leaderAuto,
        public bool $reserveMet,
    ) {}
}
