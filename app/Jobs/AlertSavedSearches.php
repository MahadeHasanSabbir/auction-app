<?php

namespace App\Jobs;

use App\Enums\AuctionStatus;
use App\Enums\UserRole;
use App\Models\Auction;
use App\Models\SavedSearch;
use App\Services\Notifications\AccountAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells every buyer whose saved search matches a newly approved auction.
 * Dispatched (after commit) when an auction is scheduled. Searches are
 * checked in PHP, a chunk at a time, against the auction as it is now.
 */
final class AlertSavedSearches implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $auctionId) {}

    public function handle(AccountAlerts $alerts): void
    {
        $auction = Auction::query()
            ->with(['product:id,name,category_id', 'product.category:id,slug,parent_id', 'product.category.parent:id,slug', 'company:id,slug'])
            ->find($this->auctionId);

        if ($auction === null || ! in_array($auction->status, [AuctionStatus::Scheduled, AuctionStatus::Live], true)) {
            return;
        }

        /** @var array<int, true> $told */
        $told = [];

        SavedSearch::query()
            ->where('alerts', true)
            ->whereHas('user', fn (Builder $user) => $user->where('role', UserRole::Buyer)->where('is_active', true))
            ->orderBy('id')
            ->chunkById(500, function (Collection $searches) use ($auction, $alerts, &$told): void {
                /** @var SavedSearch $search */
                foreach ($searches as $search) {
                    // One alert per buyer, however many of their searches match.
                    if (isset($told[$search->user_id]) || ! $search->criteria()->matches($auction)) {
                        continue;
                    }

                    $told[$search->user_id] = true;
                    $alerts->savedSearch($search, $auction);
                    $search->forceFill(['last_alerted_at' => now()])->save();
                }
            });
    }
}
