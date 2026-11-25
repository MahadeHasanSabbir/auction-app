<?php

namespace App\Events\Auctions;

/** A scheduled auction opened for bidding. */
final class AuctionStarted extends AuctionBroadcast
{
    public function broadcastAs(): string
    {
        return 'auction.started';
    }
}
