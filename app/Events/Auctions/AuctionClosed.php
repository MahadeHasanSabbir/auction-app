<?php

namespace App\Events\Auctions;

/** The auction ended: sold (by bidding or buy now), unsold or cancelled. */
final class AuctionClosed extends AuctionBroadcast
{
    public function broadcastAs(): string
    {
        return 'auction.closed';
    }
}
