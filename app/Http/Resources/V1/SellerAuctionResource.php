<?php

namespace App\Http\Resources\V1;

use App\Models\Auction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An auction as its own seller team sees it: the review state, the proposed
 * and approved schedule and the reserve.
 *
 * @mixin Auction
 */
class SellerAuctionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url(),
            'status' => $this->status->value,
            'product' => $this->whenLoaded('product', fn () => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name] : null),
            'starting_price' => $this->starting_price,
            'reserve_price' => $this->reserve_price,
            'buy_now_price' => $this->buy_now_price,
            'min_increment' => $this->min_increment,
            'current_price' => $this->final_price ?? $this->current_price,
            'bid_count' => $this->bid_count,
            'reserve_met' => $this->reserve_price === null ? null : (bool) $this->reserve_met,
            'proposed_starts_at' => $this->proposed_starts_at?->toIso8601ZuluString(),
            'proposed_ends_at' => $this->proposed_ends_at?->toIso8601ZuluString(),
            'starts_at' => $this->starts_at?->toIso8601ZuluString(),
            'ends_at' => $this->ends_at?->toIso8601ZuluString(),
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'featured' => $this->featured_at !== null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
