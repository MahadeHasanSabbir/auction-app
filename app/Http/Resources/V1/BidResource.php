<?php

namespace App\Http\Resources\V1;

use App\Models\Bid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bid in an auction's history. Bidders are masked names; the bid's own
 * maximum (for an automatic bid) is private and never shown. Load bidder first.
 *
 * @mixin Bid
 */
class BidResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'seq' => $this->seq,
            'bidder' => $this->bidder->maskedName(),
            'amount' => $this->amount,
            'placed_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
