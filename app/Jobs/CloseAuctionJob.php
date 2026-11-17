<?php

namespace App\Jobs;

use App\Actions\Auctions\CloseAuction;
use App\Models\Auction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Closes one auction. Dispatched by auctions:sweep; unique per auction, so a
 * sweep every few seconds does not pile up copies while the queue is busy.
 * CloseAuction re-checks under the row lock, so a stale job does nothing.
 */
class CloseAuctionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 60;

    public function __construct(public int $auctionId) {}

    public function uniqueId(): string
    {
        return (string) $this->auctionId;
    }

    public function handle(CloseAuction $close): void
    {
        $auction = Auction::query()->find($this->auctionId);

        if ($auction !== null) {
            $close->handle($auction);
        }
    }
}
