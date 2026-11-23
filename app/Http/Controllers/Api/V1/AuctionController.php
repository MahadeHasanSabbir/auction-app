<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\AuctionDetailResource;
use App\Http\Resources\V1\AuctionResource;
use App\Http\Resources\V1\BidResource;
use App\Models\Auction;
use App\Support\AuctionFilters;
use App\Support\AuctionListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Auctions
 *
 * The public marketplace: browse auctions and read their bid history. No token needed.
 *
 * @unauthenticated
 */
class AuctionController extends ApiController
{
    /**
     * List auctions
     *
     * Open auctions by default (live first, then those starting soon, featured ones leading).
     *
     * @queryParam q string Search words in the title. Example: clock
     * @queryParam category string A category slug; a parent category includes its children. Example: antiques
     * @queryParam company string A seller's slug. Example: dhaka-antiques
     * @queryParam min string Lowest current price in major units. Example: 100
     * @queryParam max string Highest current price in major units. Example: 5000.50
     * @queryParam status string One of open, live, upcoming, ended. Example: live
     * @queryParam sort string One of ending, newest, price_low, price_high, bids. Example: ending
     * @queryParam page integer Page number. Example: 1
     * @queryParam per_page integer Rows per page (at most 50). Example: 20
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AuctionListing::query(
            (string) $request->query('status', 'open'),
            (string) $request->query('sort', 'ending'),
            AuctionFilters::fromArray($request->query()),
        );

        return AuctionResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    /**
     * Show an auction
     *
     * Drafts, rejected and cancelled-before-start auctions do not exist for the public (404).
     *
     * @urlParam auction integer required The auction id. Example: 12
     */
    public function show(Auction $auction): AuctionDetailResource
    {
        abort_unless($auction->status->isPublic(), 404);

        $auction->load([
            'company:id,name,slug',
            'product' => fn ($query) => $query->withTrashed(),
            'product.images',
            'product.category:id,name,slug,parent_id',
            'winner:id,name',
        ]);

        return new AuctionDetailResource($auction);
    }

    /**
     * Bid history
     *
     * Newest first. Bidders are masked names; automatic-bid maximums are never shown.
     *
     * @urlParam auction integer required The auction id. Example: 12
     *
     * @queryParam page integer Page number. Example: 1
     */
    public function bids(Request $request, Auction $auction): AnonymousResourceCollection
    {
        abort_unless($auction->status->isPublic(), 404);

        $bids = $auction->bids()
            ->standing()
            ->with(['bidder' => fn ($query) => $query->withTrashed()->select(['id', 'name'])])
            ->orderByDesc('seq')
            ->paginate($this->perPage($request));

        return BidResource::collection($bids);
    }
}
