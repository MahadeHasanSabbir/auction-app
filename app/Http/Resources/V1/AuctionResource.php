<?php

namespace App\Http\Resources\V1;

use App\Models\Auction;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * An auction as the public sees it, in a list: the figures a card shows.
 * Never the reserve amount, a bidder's maximum or any user id.
 *
 * @mixin Auction
 */
class AuctionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $open = ! $this->status->isFinal();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url(),
            'status' => $this->status->value,
            'starting_price' => $this->starting_price,
            'current_price' => $this->final_price ?? $this->current_price,
            'next_minimum_bid' => $open ? $this->minimumNextBid() : null,
            'bid_count' => $this->bid_count,
            'reserve_met' => $this->reserve_price === null ? null : (bool) $this->reserve_met,
            'buy_now_price' => $this->buy_now_price !== null && $this->bid_count === 0 && $open ? $this->buy_now_price : null,
            'starts_at' => $this->starts_at?->toIso8601ZuluString(),
            'ends_at' => $this->ends_at?->toIso8601ZuluString(),
            'featured' => $this->featured_at !== null,
            'is_leading' => $viewer !== null && $this->leader_id === $viewer->getAuthIdentifier(),
            'image' => $this->whenLoaded('product', fn () => ($cover = $this->product?->coverImage) ? Storage::disk('public')->url($cover->thumbnail_path) : null),
            'category' => $this->whenLoaded('product', fn () => $this->product?->category ? ['id' => $this->product->category->id, 'name' => $this->product->category->name] : null),
            'seller' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'name' => $this->company->name, 'slug' => $this->company->slug]),
            'my_top_bid' => $this->when(isset($this->resource->my_top_bid), fn () => Money::minor((int) $this->resource->my_top_bid)),
            'server_time' => now()->toIso8601ZuluString(),
        ];
    }
}
