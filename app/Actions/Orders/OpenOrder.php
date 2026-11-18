<?php

namespace App\Actions\Orders;

use App\Enums\LedgerTransactionType;
use App\Enums\OrderStatus;
use App\Enums\SystemAccount;
use App\Models\Auction;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Services\Fees\CommissionTerms;
use App\Services\Ledger\Ledger;
use App\Support\Money;
use LogicException;

/**
 * Turns a sale into an order: the price moves from the buyer's wallet into
 * escrow (capturing the winning bid's hold, if there is one) and the order
 * records the commission fixed on the auction at approval. Runs inside the
 * caller's transaction, with the auction row already locked; the commission
 * is paid out of escrow when the order completes (SettleEscrow).
 */
final class OpenOrder
{
    public function __construct(private readonly Ledger $ledger) {}

    public function handle(Auction $auction, User $buyer, Money $price, ?WalletHold $hold, string $description): Order
    {
        $snapshot = $auction->fee_snapshot['sale_commission'] ?? null;

        if (! is_array($snapshot)) {
            throw new LogicException("Auction {$auction->id} has no commission terms; it was never approved.");
        }

        $commission = CommissionTerms::fromSnapshot($snapshot)->apply($price, $auction->starting_price);

        $order = new Order;
        $order->forceFill([
            // A placeholder until the id is known; numbers follow the id.
            'number' => 'ORD-'.bin2hex(random_bytes(8)),
            'auction_id' => $auction->id,
            'buyer_id' => $buyer->id,
            'company_id' => $auction->company_id,
            'amount' => $price,
            'commission' => $commission,
            'seller_payout' => $price->minus($commission),
            'status' => OrderStatus::InEscrow,
            'shipping_name' => $buyer->name,
            'shipping_phone' => $buyer->phone,
            // The ship-by clock runs from here when the profile has an
            // address; otherwise it is the buyer's deadline to give one, and
            // restarts for the seller when they do (UpdateDeliveryAddress).
            'shipping_address' => $buyer->address,
            'ship_by_at' => now()->addDays((int) config('auction.orders.ship_by_days')),
        ])->save();
        $order->forceFill(['number' => 'ORD-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)])->save();

        $wallet = Wallet::query()->where('user_id', $buyer->id)->firstOrFail();

        $transaction = $this->ledger->transaction(LedgerTransactionType::SaleCapture)
            ->reference($order)
            ->idempotent("sale:{$auction->id}")
            ->describe("Order {$order->number} paid into escrow")
            ->by($buyer)
            ->debit($wallet, $price)
            ->credit($this->ledger->system(SystemAccount::Escrow), $price);

        if ($hold !== null) {
            $transaction->capturing($hold);
        }

        $transaction->post();

        $event = new OrderEvent;
        $event->forceFill([
            'order_id' => $order->id,
            'event' => 'created',
            'to_status' => OrderStatus::InEscrow,
            'actor_id' => null,
            'description' => $description,
            'created_at' => now(),
        ])->save();

        return $order;
    }
}
