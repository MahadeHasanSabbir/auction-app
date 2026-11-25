<?php

namespace App\Events\Account;

use App\Models\Auction;
use App\Models\Order;

/** The user's bid won an auction when it closed. */
final class AuctionWon extends UserBroadcast
{
    public function __construct(public Auction $auction, public Order $order) {}

    protected function userId(): int
    {
        return $this->order->buyer_id;
    }

    public function broadcastAs(): string
    {
        return 'auction.won';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'auction_id' => $this->auction->id,
            'url' => route('auctions.show', $this->auction),
            'message' => __('You won “:title” for :price. Order :number is paid into escrow.', [
                'title' => $this->auction->title,
                'price' => $this->order->amount->format(),
                'number' => $this->order->number,
            ]),
        ];
    }
}
