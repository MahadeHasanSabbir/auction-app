<?php

namespace App\Jobs;

use App\Models\Auction;
use App\Models\User;
use App\Services\Trust\SharedIpCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Runs the shared-IP check off the bidding request. Dispatched after commit by PlaceBid. */
final class CheckSharedIp implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $auctionId,
        public readonly int $bidderId,
        public readonly string $ip,
    ) {}

    public function handle(SharedIpCheck $check): void
    {
        $auction = Auction::query()->find($this->auctionId);
        $bidder = User::query()->find($this->bidderId);

        if ($auction !== null && $bidder !== null) {
            $check->check($auction, $bidder, $this->ip);
        }
    }
}
