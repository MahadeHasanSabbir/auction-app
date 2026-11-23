<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Messaging\MarkConversationRead;
use App\Actions\Messaging\PostOrderMessage;
use App\Actions\Orders\CompleteOrder;
use App\Actions\Orders\LeaveReview;
use App\Actions\Orders\OpenDispute;
use App\Actions\Orders\UpdateDeliveryAddress;
use App\Enums\OrderStatus;
use App\Http\Resources\V1\MessageResource;
use App\Http\Resources\V1\OrderResource;
use App\Models\Message;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * @group Orders (buyer)
 *
 * A buyer's orders: delivery address, confirming delivery, disputes, reviews and the chat with the seller.
 * A seller team member can also read an order of their company and use its chat here;
 * the seller's own actions are under "Seller".
 *
 * @authenticated
 */
class OrderController extends ApiController
{
    /**
     * List my orders
     *
     * @queryParam tab string open (in escrow, shipped, disputed) or closed. Defaults to all. Example: open
     * @queryParam page integer Page number. Example: 1
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $open = [OrderStatus::InEscrow, OrderStatus::Shipped, OrderStatus::Disputed];

        $orders = Order::query()
            ->where('buyer_id', $this->user($request)->id)
            ->when($request->query('tab') === 'open', fn ($query) => $query->whereIn('status', $open))
            ->when($request->query('tab') === 'closed', fn ($query) => $query->whereNotIn('status', $open))
            ->with(['auction:id,title', 'company:id,name,slug'])
            ->latest('id')
            ->paginate($this->perPage($request));

        return OrderResource::collection($orders);
    }

    /**
     * Show an order
     *
     * With the timeline, shipment, dispute and review.
     *
     * @urlParam order string required The order number. Example: ORD-000123
     */
    public function show(Request $request, Order $order): OrderResource
    {
        Gate::forUser($this->user($request))->authorize('view', $order);

        return new OrderResource($this->detail($order));
    }

    /**
     * Set the delivery address
     *
     * Before the order ships.
     *
     * @urlParam order string required The order number. Example: ORD-000123
     *
     * @bodyParam shipping_name string required Name on the parcel. Example: Rahim Uddin
     * @bodyParam shipping_phone string required Phone number. Example: +8801700000000
     * @bodyParam shipping_address string required Full address, at least 10 characters. Example: House 4, Road 2, Dhanmondi, Dhaka
     */
    public function address(Request $request, Order $order, UpdateDeliveryAddress $action): OrderResource
    {
        $action->handle($this->user($request), $order, $request->only(['shipping_name', 'shipping_phone', 'shipping_address']));

        return new OrderResource($this->detail($order));
    }

    /**
     * Confirm delivery
     *
     * Releases the money to the seller. Only while the order is shipped.
     *
     * @urlParam order string required The order number. Example: ORD-000123
     */
    public function confirm(Request $request, Order $order, CompleteOrder $action): OrderResource
    {
        $action->confirm($this->user($request), $order);

        return new OrderResource($this->detail($order));
    }

    /**
     * Open a dispute
     *
     * Only while the order is shipped and before it is released automatically. Staff decide.
     *
     * @urlParam order string required The order number. Example: ORD-000123
     *
     * @bodyParam reason string required A dispute reason code, for example not_received or not_as_described. Example: not_received
     * @bodyParam description string required What went wrong, 20 to 2000 characters. Example: The parcel never arrived although tracking says delivered.
     */
    public function dispute(Request $request, Order $order, OpenDispute $action): OrderResource
    {
        $action->handle($this->user($request), $order, $request->only(['reason', 'description']));

        return new OrderResource($this->detail($order));
    }

    /**
     * Review the seller
     *
     * Once the order is completed, within the review period.
     *
     * @urlParam order string required The order number. Example: ORD-000123
     *
     * @bodyParam rating integer required 1 to 5. Example: 5
     * @bodyParam comment string Optional text. Example: Fast shipping, as described.
     */
    public function review(Request $request, Order $order, LeaveReview $action): OrderResource
    {
        $action->handle($this->user($request), $order, $request->only(['rating', 'comment']));

        return new OrderResource($this->detail($order));
    }

    /**
     * Order chat
     *
     * The latest 100 messages between the buyer and the selling team, oldest first. Reading marks them read.
     *
     * @urlParam order string required The order number. Example: ORD-000123
     */
    public function messages(Request $request, Order $order, MarkConversationRead $read): AnonymousResourceCollection
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('chat', $order);

        $conversation = $order->conversation()->first();

        if ($conversation === null) {
            return MessageResource::collection(collect());
        }

        $read->handle($user, $conversation);

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with('author:id,name')
            ->latest('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->values();

        return MessageResource::collection($messages);
    }

    /**
     * Send a chat message
     *
     * @urlParam order string required The order number. Example: ORD-000123
     *
     * @bodyParam body string required The message, up to 2000 characters. Example: Could you ship on Sunday?
     */
    public function send(Request $request, Order $order, PostOrderMessage $action): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string']]);

        $message = $action->handle($this->user($request), $order, $data['body']);
        $message->load('author:id,name');

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    private function detail(Order $order): Order
    {
        return Order::query()
            ->with(['auction:id,title', 'company:id,name,slug', 'buyer:id,name', 'shipment', 'events', 'review', 'latestDispute'])
            ->findOrFail($order->id);
    }
}
