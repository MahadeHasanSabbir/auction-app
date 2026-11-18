<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\ChangesOrders;
use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The buyer takes their dispute back: the order is shipped again and keeps
 * its auto-release date, so a passed date releases it at the next sweep.
 */
final class WithdrawDispute
{
    use ChangesOrders;

    public function handle(User $buyer, Order $order): Order
    {
        Gate::forUser($buyer)->authorize('withdrawDispute', $order);

        return DB::transaction(function () use ($buyer, $order): Order {
            $locked = $this->lockOrder($order, OrderStatus::Disputed);

            $dispute = Dispute::query()->where('order_id', $locked->id)->where('status', DisputeStatus::Open)->lockForUpdate()->sole();
            $dispute->forceFill(['status' => DisputeStatus::Withdrawn, 'resolved_at' => now()])->save();

            $from = $this->moveTo($locked, OrderStatus::Shipped);
            $locked->save();

            $this->record($locked, 'dispute_withdrawn', __('The buyer withdrew the dispute.'), $buyer, $from, OrderStatus::Shipped);

            return $this->syncFrom($order, $locked);
        });
    }
}
