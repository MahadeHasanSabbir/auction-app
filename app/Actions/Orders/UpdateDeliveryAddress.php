<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\ChangesOrders;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * The buyer gives or corrects the delivery details before the order ships.
 * The seller's ship-by clock starts when the first address arrives.
 */
final class UpdateDeliveryAddress
{
    use ChangesOrders;

    /** @param  array<string, mixed>  $input */
    public function handle(User $buyer, Order $order, array $input): Order
    {
        Gate::forUser($buyer)->authorize('updateAddress', $order);

        /** @var array{shipping_name: string, shipping_phone: string, shipping_address: string} $data */
        $data = Validator::make($input, [
            'shipping_name' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['required', 'string', 'regex:/^[0-9+\-\s()]{6,32}$/'],
            'shipping_address' => ['required', 'string', 'min:10', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($buyer, $order, $data): Order {
            $locked = $this->lockOrder($order, OrderStatus::InEscrow);
            $first = blank($locked->shipping_address);

            $locked->fill(array_map(trim(...), $data));

            if ($first) {
                $locked->ship_by_at = now()->addDays((int) config('auction.orders.ship_by_days'));
            }

            $locked->save();

            $this->record($locked, 'address', $first
                ? __('The buyer added the delivery address.')
                : __('The buyer changed the delivery address.'), $buyer);

            return $this->syncFrom($order, $locked);
        });
    }
}
