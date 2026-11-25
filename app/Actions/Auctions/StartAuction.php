<?php

namespace App\Actions\Auctions;

use App\Enums\AuctionStatus;
use App\Events\Auctions\AuctionStarted;
use App\Models\Auction;
use Illuminate\Support\Facades\DB;

/** Opens bidding on a scheduled auction whose start time has come. */
final class StartAuction
{
    /** @return bool whether this call started it */
    public function handle(Auction $auction): bool
    {
        return DB::transaction(function () use ($auction): bool {
            $locked = Auction::query()->lockForUpdate()->find($auction->id);

            if ($locked === null || $locked->status !== AuctionStatus::Scheduled || $locked->starts_at === null || now()->lessThan($locked->starts_at)) {
                return false;
            }

            $locked->transitionTo(AuctionStatus::Live);
            $locked->save();

            AuctionStarted::dispatch($locked);

            $auction->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
