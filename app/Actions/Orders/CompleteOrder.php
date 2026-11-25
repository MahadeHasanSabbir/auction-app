<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\ChangesOrders;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Releases a shipped order's escrow to the company: when the buyer confirms
 * the item arrived, or by itself once the auto-release date has passed
 * (orders:sweep). An open dispute (status disputed) stops both.
 */
final class CompleteOrder
{
    use ChangesOrders;

    public function __construct(private readonly SettleEscrow $settle) {}

    /** The buyer confirms they received the item. */
    public function confirm(User $buyer, Order $order): Order
    {
        Gate::forUser($buyer)->authorize('confirm', $order);

        return DB::transaction(function () use ($buyer, $order): Order {
            $locked = $this->lockOrder($order, OrderStatus::Shipped);

            Shipment::query()->where('order_id', $locked->id)->where('status', ShipmentStatus::InTransit)
                ->update(['status' => ShipmentStatus::Delivered, 'delivered_at' => now()]);
            $locked->delivered_at ??= now();

            $this->complete($locked, $buyer, __('The buyer confirmed the item arrived; payment released to the seller.'));

            return $this->syncFrom($order, $locked);
        }, attempts: 3);
    }

    /** @return bool whether this call released it */
    public function autoRelease(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $locked = Order::query()->lockForUpdate()->find($order->id);

            if ($locked === null || $locked->status !== OrderStatus::Shipped
                || $locked->auto_release_at === null || now()->lessThan($locked->auto_release_at)) {
                return false;
            }

            $this->complete($locked, null, __('No problem was reported in time; payment released to the seller.'));
            $this->syncFrom($order, $locked);

            return true;
        }, attempts: 3);
    }

    private function complete(Order $locked, ?User $actor, string $description): void
    {
        $this->settle->handle($locked, Money::zero($locked->amount->currency), $actor, "Order {$locked->number} completed");

        $from = $this->moveTo($locked, OrderStatus::Completed);
        $locked->completed_at = now();
        $locked->save();

        $this->record($locked, 'completed', $description, $actor, $from, OrderStatus::Completed, [
            'payout' => $locked->seller_payout->amount,
            'commission' => $locked->commission->amount,
        ]);
    }
}
