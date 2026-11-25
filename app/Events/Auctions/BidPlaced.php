<?php

namespace App\Events\Auctions;

/** One or more bids were written (a proxy answer is part of the same event). */
final class BidPlaced extends AuctionBroadcast
{
    public function broadcastAs(): string
    {
        return 'bid.placed';
    }
}
