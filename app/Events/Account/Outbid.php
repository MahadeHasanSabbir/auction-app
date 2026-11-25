<?php

namespace App\Events\Account;

use App\Models\Auction;

/** The user led an auction and another bidder's maximum has taken the lead. */
final class Outbid extends UserBroadcast
{
    public function __construct(public int $recipientId, public Auction $auction) {}

    protected function userId(): int
    {
        return $this->recipientId;
    }

    public function broadcastAs(): string
    {
        return 'bid.outbid';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $price = $this->auction->current_price->format();

        return [
            'auction_id' => $this->auction->id,
            'url' => route('auctions.show', $this->auction),
            'message' => __('You have been outbid on “:title”. The price is now :price.', ['title' => $this->auction->title, 'price' => $price]),
        ];
    }
}
