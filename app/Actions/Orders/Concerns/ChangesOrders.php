<?php

namespace App\Actions\Orders\Concerns;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Services\Notifications\OrderAlerts;
use Illuminate\Validation\ValidationException;

/** The steps every order status change shares. */
trait ChangesOrders
{
    /**
     * Locks the order (the domain row comes first in the lock order) and
     * checks it is in one of $statuses. Must run inside a DB transaction.
     */
    private function lockOrder(Order $order, OrderStatus ...$statuses): Order
    {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

        if (! in_array($locked->status, $statuses, true)) {
            throw ValidationException::withMessages([
                'order' => __('This order is :status.', ['status' => mb_strtolower($locked->status->label())]),
            ]);
        }

        return $locked;
    }

    /** Moves the locked order to $next, which its status must allow. */
    private function moveTo(Order $locked, OrderStatus $next): OrderStatus
    {
        $from = $locked->status;

        if (! $from->canTransitionTo($next)) {
            throw new \LogicException("Order {$locked->id} cannot go from {$from->value} to {$next->value}.");
        }

        $locked->status = $next;

        return $from;
    }

    /**
     * Adds a line to the order timeline.
     *
     * @param  array<string, mixed>  $meta
     */
    private function record(Order $order, string $event, string $description, ?User $actor = null, ?OrderStatus $from = null, ?OrderStatus $to = null, array $meta = []): void
    {
        $line = new OrderEvent;
        $line->forceFill([
            'order_id' => $order->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor?->id,
            'description' => $description,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ])->save();

        app(OrderAlerts::class)->announce($order, $event, $description, $actor);
    }

    /** Copies the locked row's state onto the caller's model. */
    private function syncFrom(Order $order, Order $locked): Order
    {
        $order->setRawAttributes($locked->getAttributes(), true);
        $order->unsetRelations();

        return $order;
    }
}
