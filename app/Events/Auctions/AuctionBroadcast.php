<?php

namespace App\Events\Auctions;

use App\Models\Auction;
use App\Services\Realtime\AuctionSnapshot;
use App\Services\Realtime\ViewerCount;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something visible changed on an auction. Sent on its public channel
 * `auction.{id}` once the transaction commits, from the broadcasts queue.
 * The auction is read again when the broadcast is sent, so the payload is
 * the latest state (AuctionSnapshot), never an older one.
 */
abstract class AuctionBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Auction $auction) {}

    public function broadcastOn(): Channel
    {
        return new Channel('auction.'.$this->auction->id);
    }

    abstract public function broadcastAs(): string;

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return AuctionSnapshot::of($this->auction, viewers: app(ViewerCount::class)->of($this->auction->id));
    }

    public function broadcastQueue(): string
    {
        return (string) config('auction.realtime.queue');
    }
}
