<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\ChangesOrders;
use App\Actions\Trust\IssueStrike;
use App\Enums\OrderStatus;
use App\Enums\StrikeSource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an order that has not shipped and refunds the buyer in full: the
 * company owner does it (with a reason), or orders:sweep once the ship-by
 * date has passed, whether the seller did not ship or the buyer gave no
 * address. The product can be listed again. A buyer who gave no address
 * gets a strike.
 */
final class CancelOrder
{
    use ChangesOrders;

    public function __construct(
        private readonly SettleEscrow $settle,
        private readonly IssueStrike $strikes,
    ) {}

    public function handle(User $owner, Order $order, string $reason): Order
    {
        Gate::forUser($owner)->authorize('cancel', $order);

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Tell the buyer why, in up to 500 characters.')]);
        }

        return DB::transaction(function () use ($owner, $order, $reason): Order {
            $locked = $this->lockOrder($order, OrderStatus::InEscrow);
            $this->cancel($locked, $owner, $reason);

            return $this->syncFrom($order, $locked);
        }, attempts: 3);
    }

    /** @return bool whether this call cancelled it */
    public function overdue(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $locked = Order::query()->lockForUpdate()->find($order->id);

            if ($locked === null || $locked->status !== OrderStatus::InEscrow
                || $locked->ship_by_at === null || now()->lessThan($locked->ship_by_at)) {
                return false;
            }

            $buyerAtFault = blank($locked->shipping_address);

            $this->cancel($locked, null, $buyerAtFault
                ? __('The buyer did not give a delivery address in time.')
                : __('The seller did not ship in time.'));

            if ($buyerAtFault) {
                $buyer = User::query()->find($locked->buyer_id);

                if ($buyer !== null) {
                    $this->strikes->automatic($buyer, StrikeSource::UnpaidOrder,
                        __('Order :number was cancelled: no delivery address was given in time.', ['number' => $locked->number]), $locked);
                }
            }

            $this->syncFrom($order, $locked);

            return true;
        }, attempts: 3);
    }

    private function cancel(Order $locked, ?User $actor, string $reason): void
    {
        $this->settle->handle($locked, $locked->paid(), $actor, "Order {$locked->number} cancelled, buyer refunded");

        $from = $this->moveTo($locked, OrderStatus::Cancelled);
        $locked->forceFill(['cancelled_at' => now(), 'cancellation_reason' => $reason])->save();

        $this->record($locked, 'cancelled', __('Order cancelled and the buyer refunded in full: :reason', ['reason' => $reason]), $actor, $from, OrderStatus::Cancelled);
    }
}
