<?php

namespace App\Http\Controllers;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use Illuminate\View\View;

/**
 * The public list of live and upcoming auctions.
 *
 * A plain listing for now; search, filters and live updates arrive with the
 * Livewire rebuild of the public site.
 */
class AuctionListController extends Controller
{
    public function __invoke(): View
    {
        $auctions = Auction::query()
            ->whereIn('status', [AuctionStatus::Live, AuctionStatus::Scheduled])
            ->with(['company:id,name,slug', 'product:id,category_id', 'product.category:id,name', 'product.coverImage'])
            // "live" sorts before "scheduled"; then whatever ends or starts soonest.
            ->orderBy('status')
            ->orderBy('ends_at')
            ->paginate(12);

        return view('auctions.index', ['auctions' => $auctions]);
    }
}
