<?php

namespace App\Services\Bidding;

use App\Enums\BidType;
use App\Support\Money;

/** One row ProxyResolver wants written to the bids table. */
final readonly class ResolvedBid
{
    public function __construct(
        public int $userId,
        public Money $amount,
        public BidType $type,
    ) {}
}
