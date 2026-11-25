<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bidding\BuyNow;
use App\Actions\Bidding\PlaceBid;
use App\Actions\Bidding\ToggleWatchlist;
use App\Enums\AuctionStatus;
use App\Http\Resources\V1\AuctionResource;
use App\Http\Resources\V1\OrderResource;
use App\Models\Auction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Bidding
 *
 * Buyers bid, buy now and watch auctions. The same rules apply as on the website: a verified email,
 * cleared identity check, the biddable share of the wallet, and the per-minute bid limit.
 *
 * @authenticated
 */
class BiddingController extends ApiController
{
    /**
     * Place a bid
     *
     * With `auto` false the amount is the bid. With `auto` true the amount is your maximum: the system
     * bids for you up to it, and the maximum is held in your wallet while you lead. Send an
     * `Idempotency-Key` header (any unique string) to make a retry safe: a repeated key returns the first answer.
     *
     * @urlParam auction integer required The auction id. Example: 12
     *
     * @bodyParam amount string required Amount in major units. Example: 1500.00
     * @bodyParam auto boolean Treat the amount as an automatic-bid maximum. Example: false
     *
     * @response 200 {"data":{"leading":true,"price":{"amount":150000,"currency":"BDT","formatted":"BDT 1,500.00"},"next_minimum":{"amount":160000,"currency":"BDT","formatted":"BDT 1,600.00"},"extended":false,"message":"You are the highest bidder at BDT 1,500.00."}}
     */
    public function bid(Request $request, Auction $auction, PlaceBid $placeBid): JsonResponse
    {
        abort_unless($auction->status->isPublic(), 404);

        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'auto' => ['sometimes', 'boolean'],
        ]);

        $result = $placeBid->handle(
            $this->user($request),
            $auction,
            (string) $data['amount'],
            (bool) ($data['auto'] ?? false),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => [
            'leading' => $result->leading,
            'price' => $result->price,
            'next_minimum' => $result->nextMinimum,
            'extended' => $result->extended,
            'message' => $result->message(),
        ]]);
    }

    /**
     * Buy now
     *
     * Buys at the buy-now price, only while the auction has no bids. The full price must be available in the
     * wallet. Opens an order with the price held in escrow.
     *
     * @urlParam auction integer required The auction id. Example: 12
     */
    public function buyNow(Request $request, Auction $auction, BuyNow $buyNow): JsonResponse
    {
        abort_unless($auction->status->isPublic(), 404);

        $order = $buyNow->handle($this->user($request), $auction, $this->idempotencyKey($request));
        $order->load(['auction:id,title', 'company:id,name,slug']);

        return (new OrderResource($order))->response()->setStatusCode(201);
    }

    /**
     * Watch or unwatch
     *
     * Toggles the auction on your watchlist.
     *
     * @urlParam auction integer required The auction id. Example: 12
     *
     * @response 200 {"data":{"watching":true}}
     */
    public function watch(Request $request, Auction $auction, ToggleWatchlist $toggle): JsonResponse
    {
        abort_unless($auction->status->isPublic(), 404);

        return response()->json(['data' => ['watching' => $toggle->handle($this->user($request), $auction)]]);
    }

    /**
     * My auctions
     *
     * Auctions you bid on or watch, with your own top bid.
     *
     * @queryParam tab string One of bidding (live auctions you bid on), won, lost, watching. Example: bidding
     * @queryParam page integer Page number. Example: 1
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $mine = fn (Builder $query) => $query->where('user_id', $user->id)->whereNull('retracted_at');

        $query = match ($request->query('tab')) {
            'won' => Auction::query()->where('status', AuctionStatus::Sold)->where('winner_id', $user->id)->orderByDesc('closed_at'),
            'lost' => Auction::query()
                ->whereIn('status', [AuctionStatus::Sold, AuctionStatus::Unsold, AuctionStatus::Cancelled])
                ->whereHas('bids', $mine)
                ->where(fn (Builder $q) => $q->whereNull('winner_id')->orWhere('winner_id', '!=', $user->id))
                ->orderByDesc('closed_at'),
            'watching' => Auction::query()
                ->join('watchlists', 'watchlists.auction_id', '=', 'auctions.id')
                ->where('watchlists.user_id', $user->id)
                ->select('auctions.*')
                ->orderByDesc('watchlists.created_at'),
            default => Auction::query()->where('status', AuctionStatus::Live)->whereHas('bids', $mine)->orderBy('ends_at'),
        };

        return AuctionResource::collection(
            $query->forCards()->withMax(['bids as my_top_bid' => $mine], 'amount')->paginate($this->perPage($request)),
        );
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        // The key column holds 64 characters; a longer key would be stored cut
        // and never found again, so a retry would not get the first answer.
        abort_if(strlen($key) > 64, 422, __('The Idempotency-Key header may be at most 64 characters.'));

        return $key;
    }
}
