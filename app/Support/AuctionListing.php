<?php

namespace App\Support;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use Illuminate\Database\Eloquent\Builder;

/**
 * The public auction list as a query: which statuses a "status" choice shows,
 * the filters and the sort order. Shared by the list page and the API.
 */
final class AuctionListing
{
    /** Which auctions each "status" choice shows. */
    public const STATUSES = [
        'open' => [AuctionStatus::Live, AuctionStatus::Scheduled],
        'live' => [AuctionStatus::Live],
        'upcoming' => [AuctionStatus::Scheduled],
        'ended' => [AuctionStatus::Sold, AuctionStatus::Unsold],
    ];

    public const SORTS = ['ending', 'newest', 'price_low', 'price_high', 'bids'];

    /**
     * Unknown choices fall back to the defaults (open auctions, ending soonest).
     *
     * @return Builder<Auction>
     */
    public static function query(string $status, string $sort, AuctionFilters $filters): Builder
    {
        $statuses = self::STATUSES[$status] ?? self::STATUSES['open'];
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'ending';
        $ended = $status === 'ended';

        $query = Auction::query()->forCards()->whereIn('status', $statuses);

        $filters->apply($query);

        // Featured auctions lead the default order of open auctions.
        if ($sort === 'ending' && ! $ended) {
            $query->orderByRaw('featured_at is null');
        }

        return match ($sort) {
            'newest' => $query->latest('id'),
            'price_low' => $query->orderBy('current_price')->orderBy('id'),
            'price_high' => $query->orderByDesc('current_price')->orderBy('id'),
            'bids' => $query->orderByDesc('bid_count')->orderBy('id'),
            // Live before scheduled ("live" sorts first), then whatever ends
            // or starts soonest; finished auctions newest first.
            default => $ended
                ? $query->orderByDesc('closed_at')->orderByDesc('id')
                : $query->orderBy('status')->orderByRaw('coalesce(ends_at, starts_at)')->orderBy('id'),
        };
    }
}
