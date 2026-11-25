<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Actions\Auctions\CancelAuction;
use App\Actions\Auctions\SaveAuction;
use App\Actions\Auctions\SubmitAuction;
use App\Actions\Auctions\WithdrawAuction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\V1\SellerAuctionResource;
use App\Models\Auction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Seller: auctions
 *
 * Draft, submit and manage the company's auctions. Platform staff approve each one before it runs.
 * Prices are in major units ("1500" or "1500.50"); times are ISO 8601 and read as UTC unless they carry an offset.
 *
 * @authenticated
 */
class AuctionController extends ApiController
{
    private const FIELDS = ['product_id', 'title', 'starting_price', 'reserve_price', 'buy_now_price', 'min_increment', 'proposed_starts_at', 'proposed_ends_at'];

    /**
     * List auctions
     *
     * @queryParam status string Only this status (draft, pending_review, scheduled, live, sold, unsold, cancelled, rejected). Example: draft
     * @queryParam page integer Page number. Example: 1
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $auctions = Auction::query()
            ->where('company_id', $this->company($request)->id)
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', (string) $status))
            ->with('product:id,name')
            ->latest('id')
            ->paginate($this->perPage($request));

        return SellerAuctionResource::collection($auctions);
    }

    /**
     * Show an auction
     *
     * @urlParam auction integer required The auction id. Example: 12
     */
    public function show(Request $request, int $auction): SellerAuctionResource
    {
        return new SellerAuctionResource($this->find($request, $auction));
    }

    /**
     * Create a draft
     *
     * @bodyParam product_id integer required One of your products. Example: 7
     * @bodyParam title string required 3 to 255 characters. Example: Brass table clock, 1950s
     * @bodyParam starting_price string required Example: 1000
     * @bodyParam min_increment string required Example: 50
     * @bodyParam reserve_price string Not lower than the starting price. Example: 2000
     * @bodyParam buy_now_price string Higher than the starting price. Example: 4000
     * @bodyParam proposed_starts_at string required When you would like it to start. Example: 2026-12-01T10:00:00Z
     * @bodyParam proposed_ends_at string required When you would like it to end. Example: 2026-12-04T10:00:00Z
     */
    public function store(Request $request, SaveAuction $save): JsonResponse
    {
        $auction = $save->handle($this->user($request), $this->company($request), null, $request->only(self::FIELDS));

        return (new SellerAuctionResource($this->find($request, $auction->id)))->response()->setStatusCode(201);
    }

    /**
     * Update a draft
     *
     * Only a draft or a rejected auction can be changed (a rejected one becomes a draft again). Send all fields.
     *
     * @urlParam auction integer required The auction id. Example: 12
     *
     * @bodyParam product_id integer required One of your products. Example: 7
     * @bodyParam title string required Example: Brass table clock, 1950s
     * @bodyParam starting_price string required Example: 1000
     * @bodyParam min_increment string required Example: 50
     * @bodyParam reserve_price string Example: 2000
     * @bodyParam buy_now_price string Example: 4000
     * @bodyParam proposed_starts_at string required Example: 2026-12-01T10:00:00Z
     * @bodyParam proposed_ends_at string required Example: 2026-12-04T10:00:00Z
     */
    public function update(Request $request, int $auction, SaveAuction $save): SellerAuctionResource
    {
        $model = $this->find($request, $auction);

        $save->handle($this->user($request), $this->company($request), $model, $request->only(self::FIELDS));

        return new SellerAuctionResource($this->find($request, $model->id));
    }

    /**
     * Submit for review
     *
     * Needs a verified company on a plan that allows selling, a product with a picture, a start in the
     * future and room in the plan's active-auction cap. An auto-approved company goes straight to scheduled.
     *
     * @urlParam auction integer required The auction id. Example: 12
     */
    public function submit(Request $request, int $auction, SubmitAuction $action): SellerAuctionResource
    {
        $model = $this->find($request, $auction);
        $action->handle($this->user($request), $model);

        return new SellerAuctionResource($this->find($request, $model->id));
    }

    /**
     * Withdraw from review
     *
     * Back to a draft.
     *
     * @urlParam auction integer required The auction id. Example: 12
     */
    public function withdraw(Request $request, int $auction, WithdrawAuction $action): SellerAuctionResource
    {
        $model = $this->find($request, $auction);
        $action->handle($this->user($request), $model);

        return new SellerAuctionResource($this->find($request, $model->id));
    }

    /**
     * Cancel an auction
     *
     * Any team member before approval; the owner after approval, and a live auction only while it has no bids.
     *
     * @urlParam auction integer required The auction id. Example: 12
     *
     * @bodyParam reason string Why. Example: Item no longer available.
     */
    public function cancel(Request $request, int $auction, CancelAuction $action): SellerAuctionResource
    {
        $model = $this->find($request, $auction);
        $action->handle($this->user($request), $model, $request->filled('reason') ? (string) $request->input('reason') : null);

        return new SellerAuctionResource($this->find($request, $model->id));
    }

    /** One of this company's auctions, or 404. */
    private function find(Request $request, int $id): Auction
    {
        return Auction::query()
            ->where('company_id', $this->company($request)->id)
            ->with('product:id,name')
            ->findOrFail($id);
    }
}
