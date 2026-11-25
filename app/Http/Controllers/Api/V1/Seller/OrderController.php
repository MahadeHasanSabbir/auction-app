<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\ShipOrder;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\V1\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Seller: orders
 *
 * The company's sales. The whole team ships; only the owner cancels. Read one order, and use its chat,
 * with the buyer endpoints under "Orders (buyer)", which accept the selling team too.
 *
 * @authenticated
 */
class OrderController extends ApiController
{
    /**
     * List orders
     *
     * @queryParam status string Only this status (in_escrow, shipped, completed, cancelled, disputed, refunded, partially_refunded). Example: in_escrow
     * @queryParam page integer Page number. Example: 1
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->where('company_id', $this->company($request)->id)
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', (string) $status))
            ->with(['auction:id,title', 'company:id,name,slug', 'buyer:id,name'])
            ->latest('id')
            ->paginate($this->perPage($request));

        return OrderResource::collection($orders);
    }

    /**
     * Ship an order
     *
     * Needs the buyer's delivery address. Starts the buyer's confirmation and auto-release clock.
     *
     * @urlParam order string required The order number. Example: ORD-000123
     *
     * @bodyParam courier string required Example: Pathao
     * @bodyParam tracking_number string Example: PT123456
     * @bodyParam tracking_url string Example: https://track.example.com/PT123456
     * @bodyParam note string Example: Left with reception.
     */
    public function ship(Request $request, Order $order, ShipOrder $action): OrderResource
    {
        $this->own($request, $order);

        $action->handle($this->user($request), $order, $request->only(['courier', 'tracking_number', 'tracking_url', 'note']));

        return new OrderResource($this->detail($order));
    }

    /**
     * Cancel an order
     *
     * Owner only, before shipping. The buyer is refunded in full.
     *
     * @urlParam order string required The order number. Example: ORD-000123
     *
     * @bodyParam reason string required Example: Item damaged before shipping.
     */
    public function cancel(Request $request, Order $order, CancelOrder $action): OrderResource
    {
        $this->own($request, $order);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $action->handle($this->user($request), $order, $data['reason']);

        return new OrderResource($this->detail($order));
    }

    /** Another company's order does not exist for this one. */
    private function own(Request $request, Order $order): void
    {
        abort_unless($order->company_id === $this->company($request)->id, 404);
    }

    private function detail(Order $order): Order
    {
        return Order::query()
            ->with(['auction:id,title', 'company:id,name,slug', 'buyer:id,name', 'shipment', 'events', 'review', 'latestDispute'])
            ->findOrFail($order->id);
    }
}
