<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\ChangesOrders;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The company records that it sent the item, with the courier and tracking.
 * The auto-release date starts now; until then the buyer can confirm
 * delivery or open a dispute.
 */
final class ShipOrder
{
    use ChangesOrders;

    /** @param  array<string, mixed>  $input */
    public function handle(User $member, Order $order, array $input): Order
    {
        Gate::forUser($member)->authorize('ship', $order);

        /** @var array{courier: string, tracking_number?: ?string, tracking_url?: ?string, note?: ?string} $data */
        $data = Validator::make($input, [
            'courier' => ['required', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'tracking_url' => ['nullable', 'url:http,https', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($member, $order, $data): Order {
            $locked = $this->lockOrder($order, OrderStatus::InEscrow);

            if (blank($locked->shipping_address)) {
                throw ValidationException::withMessages(['courier' => __('The buyer has not given a delivery address yet.')]);
            }

            $shipment = new Shipment;
            $shipment->fill($data)->forceFill([
                'order_id' => $locked->id,
                'status' => ShipmentStatus::InTransit,
                'shipped_at' => now(),
                'created_by' => $member->id,
            ])->save();

            $from = $this->moveTo($locked, OrderStatus::Shipped);
            $locked->forceFill([
                'shipped_at' => now(),
                'auto_release_at' => now()->addDays((int) config('auction.orders.auto_release_days')),
            ])->save();

            $this->record($locked, 'shipped', __('Shipped with :courier.', ['courier' => $data['courier']]), $member, $from, OrderStatus::Shipped, array_filter([
                'courier' => $data['courier'],
                'tracking_number' => $data['tracking_number'] ?? null,
            ]));

            return $this->syncFrom($order, $locked);
        });
    }
}
