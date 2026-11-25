<?php

namespace App\Http\Resources\V1;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The balances of a buyer's or a company's wallet.
 *
 * @mixin Wallet
 */
class WalletResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'currency' => $this->currency,
            'balance' => $this->balance,
            'held' => $this->held,
            'available' => $this->available(),
            'bonus' => $this->bonus,
            'withdrawable' => $this->withdrawable(),
            // What a buyer may put into a bid: a share of the available balance.
            'biddable' => $this->available()->basisPointsFloor((int) config('auction.bidding.biddable_share_bps')),
        ];
    }
}
