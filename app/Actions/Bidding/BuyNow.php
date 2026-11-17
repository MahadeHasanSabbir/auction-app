<?php

namespace App\Actions\Bidding;

use App\Actions\Bidding\Concerns\BidsOnAuctions;
use App\Actions\Orders\OpenOrder;
use App\Enums\AuctionCloseReason;
use App\Enums\AuctionStatus;
use App\Enums\BidType;
use App\Events\Auctions\AuctionClosed;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Order;
use App\Models\User;
use App\Services\Ledger\InsufficientFundsException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A buyer ends a live auction at once by paying the buy-now price. Offered
 * only until the first bid, so no bid hold exists to release. The price is
 * paid from the available balance in full (the biddable share does not
 * apply) straight into escrow, and the order is opened.
 */
final class BuyNow
{
    use BidsOnAuctions;

    public function __construct(private readonly OpenOrder $openOrder) {}

    public function handle(User $buyer, Auction $auction, ?string $key = null): Order
    {
        Gate::forUser($buyer)->authorize('bid', $auction);

        if (($stored = $this->remembered($buyer, $key, 'buy_now')) !== null) {
            return Order::query()->findOrFail($stored['order_id'] ?? 0);
        }

        $this->throttle($buyer);

        try {
            return DB::transaction(fn () => $this->buy($buyer, $auction, $key), attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            if (($stored = $this->remembered($buyer, $key, 'buy_now')) !== null) {
                return Order::query()->findOrFail($stored['order_id'] ?? 0);
            }

            throw $e;
        }
    }

    private function buy(User $buyer, Auction $auction, ?string $key): Order
    {
        $claim = $this->claim($buyer, $key, 'buy_now');

        $locked = Auction::query()->lockForUpdate()->findOrFail($auction->id);
        $price = $locked->buy_now_price;

        if ($locked->status !== AuctionStatus::Live || $locked->ends_at === null || ! now()->lessThan($locked->ends_at)) {
            throw ValidationException::withMessages(['buy_now' => __('This auction is not open.')]);
        }

        if ($price === null || $locked->bid_count > 0) {
            throw ValidationException::withMessages(['buy_now' => __('Buy now is no longer offered on this auction.')]);
        }

        $wallet = $this->walletOf($buyer);

        if ($wallet->available()->lessThan($price)) {
            throw ValidationException::withMessages(['buy_now' => __('You need :amount available. Add money to your wallet first.', ['amount' => $price->format()])]);
        }

        $bid = new Bid;
        $bid->forceFill([
            'auction_id' => $locked->id,
            'user_id' => $buyer->id,
            'amount' => $price,
            'type' => BidType::BuyNow,
            'seq' => 1,
            'created_at' => now(),
        ])->save();

        $locked->transitionTo(AuctionStatus::Sold);
        $locked->forceFill([
            'close_reason' => AuctionCloseReason::BuyNow,
            'current_price' => $price,
            'bid_count' => 1,
            'leader_id' => $buyer->id,
            'reserve_met' => true,
            'winner_id' => $buyer->id,
            'final_price' => $price,
            'closed_at' => now(),
        ])->save();

        try {
            $order = $this->openOrder->handle($locked, $buyer, $price, null, 'Bought at the buy-now price; payment is held in escrow.');
        } catch (InsufficientFundsException) {
            throw ValidationException::withMessages(['buy_now' => __('You need :amount available. Add money to your wallet first.', ['amount' => $price->format()])]);
        }

        $claim?->forceFill(['response' => ['order_id' => $order->id]])->save();

        AuctionClosed::dispatch($locked);
        $auction->setRawAttributes($locked->getAttributes(), true);

        return $order;
    }
}
