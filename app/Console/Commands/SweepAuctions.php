<?php

namespace App\Console\Commands;

use App\Actions\Auctions\StartAuction;
use App\Enums\AuctionStatus;
use App\Jobs\CloseAuctionJob;
use App\Models\Auction;
use Illuminate\Console\Command;
use Throwable;

/**
 * Opens scheduled auctions whose start has come and queues the closing of
 * live auctions whose end has passed. Runs every few seconds. A sweep is
 * used instead of delayed jobs because anti-sniping moves the end time;
 * bids are refused by the end time inside their transaction, so a late
 * sweep never lets a bid in after the close.
 */
class SweepAuctions extends Command
{
    protected $signature = 'auctions:sweep';

    protected $description = 'Start scheduled auctions that are due and queue the closing of ended ones';

    public function handle(StartAuction $start): int
    {
        $started = 0;
        $closing = 0;

        Auction::query()
            ->where('status', AuctionStatus::Scheduled)
            ->where('starts_at', '<=', now())
            ->select('id')
            ->lazyById(100)
            ->each(function (Auction $auction) use ($start, &$started): void {
                try {
                    $started += $start->handle($auction) ? 1 : 0;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        Auction::query()
            ->where('status', AuctionStatus::Live)
            ->where('ends_at', '<=', now())
            ->select('id')
            ->lazyById(100)
            ->each(function (Auction $auction) use (&$closing): void {
                CloseAuctionJob::dispatch($auction->id);
                $closing++;
            });

        if ($started + $closing > 0) {
            $this->info("Started {$started}, closing {$closing}.");
        }

        return self::SUCCESS;
    }
}
