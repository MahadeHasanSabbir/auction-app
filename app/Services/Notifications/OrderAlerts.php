<?php

namespace App\Services\Notifications;

use App\Enums\NotificationKind;
use App\Filament\Seller\Resources\Orders\OrderResource;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;

/**
 * Who hears about each step of an order, and what they are told. The order
 * actions record a timeline event for every step and call this with it
 * (ChangesOrders::record), so the notifications cannot drift from the
 * timeline. The person who did the thing is not told about it.
 */
class OrderAlerts
{
    public function __construct(private readonly Notifier $notifier) {}

    /** A sale just opened this order. */
    public function created(Order $order): void
    {
        $item = $this->item($order);

        $this->notifier->send($order->buyer_id, NotificationKind::AuctionWon,
            __('You won “:item”', ['item' => $item]),
            __('Order :number is open and your payment is held safely in escrow. Check your delivery address so the seller can ship.', ['number' => $order->number]),
            $this->buyerUrl($order));

        $this->notifier->send($this->notifier->team($order->company_id), NotificationKind::NewOrder,
            __('New order: :item', ['item' => $item]),
            __('Order :number is paid. Ship it once the buyer\'s delivery address is in.', ['number' => $order->number]),
            $this->sellerUrl($order));
    }

    /** One line was added to the order's timeline. */
    public function announce(Order $order, string $event, string $description, ?User $actor): void
    {
        $item = $this->item($order);
        $buyer = $order->buyer_id;
        $team = $this->notifier->team($order->company_id);
        $others = fn (iterable $users) => collect($users)->reject(fn (User $user) => $user->id === $actor?->id);

        match ($event) {
            'address' => $this->notifier->send($team, NotificationKind::AddressReceived,
                __('Delivery address received'),
                __('The buyer gave an address for “:item” (:number).', ['item' => $item, 'number' => $order->number]),
                $this->sellerUrl($order)),

            'shipped' => $this->notifier->send($buyer, NotificationKind::OrderShipped,
                __('Your order has shipped'),
                __('“:item” (:number) is on its way. Confirm delivery when it arrives, or the payment is released automatically.', ['item' => $item, 'number' => $order->number]),
                $this->buyerUrl($order)),

            'completed' => $this->completed($order, $item, $actor, $team),

            'cancelled' => $this->cancelled($order, $description, $others($team)),

            'disputed' => $this->notifier->send($team, NotificationKind::DisputeOpened,
                __('Dispute opened on :number', ['number' => $order->number]),
                __('The buyer reported a problem with “:item”. The platform will look into it.', ['item' => $item]),
                $this->sellerUrl($order)),

            'dispute_withdrawn' => $this->notifier->send($team, NotificationKind::DisputeWithdrawn,
                __('Dispute withdrawn on :number', ['number' => $order->number]),
                __('The buyer withdrew the dispute on “:item”. The order goes on.', ['item' => $item]),
                $this->sellerUrl($order)),

            'dispute_resolved' => $this->resolved($order, $description, $team),

            'discounted' => $this->notifier->send($team, NotificationKind::OrderDiscounted,
                __('Coupon used on :number', ['number' => $order->number]),
                $description,
                $this->sellerUrl($order)),

            'reviewed' => $this->notifier->send($team, NotificationKind::ReviewLeft,
                __('New review on :number', ['number' => $order->number]),
                $description,
                $this->sellerUrl($order)),

            default => null,
        };
    }

    /** The seller answered the buyer's review. */
    public function reviewReplied(Order $order): void
    {
        $this->notifier->send($order->buyer_id, NotificationKind::ReviewReplied,
            __('The seller answered your review'),
            __('Your review of order :number has a reply.', ['number' => $order->number]),
            $this->buyerUrl($order));
    }

    /** @param  iterable<User>  $team */
    private function completed(Order $order, string $item, ?User $actor, iterable $team): void
    {
        $this->notifier->send($team, NotificationKind::OrderCompleted,
            __('Payment released for :number', ['number' => $order->number]),
            __('“:item” is complete and your payout is in the company wallet.', ['item' => $item]),
            $this->sellerUrl($order));

        // The buyer confirmed it themselves, or it was released for them.
        if ($actor === null) {
            $this->notifier->send($order->buyer_id, NotificationKind::OrderCompleted,
                __('Order :number is complete', ['number' => $order->number]),
                __('Delivery was not disputed in time, so the payment for “:item” was released to the seller.', ['item' => $item]),
                $this->buyerUrl($order));
        }
    }

    /** @param  iterable<User>  $team */
    private function cancelled(Order $order, string $description, iterable $team): void
    {
        $this->notifier->send($order->buyer_id, NotificationKind::OrderCancelled,
            __('Order :number was cancelled', ['number' => $order->number]),
            $description,
            $this->buyerUrl($order));

        $this->notifier->send($team, NotificationKind::OrderCancelled,
            __('Order :number was cancelled', ['number' => $order->number]),
            $description,
            $this->sellerUrl($order));
    }

    /** @param  iterable<User>  $team */
    private function resolved(Order $order, string $description, iterable $team): void
    {
        $this->notifier->send($order->buyer_id, NotificationKind::DisputeResolved,
            __('Your dispute on :number was decided', ['number' => $order->number]),
            $description,
            $this->buyerUrl($order));

        $this->notifier->send($team, NotificationKind::DisputeResolved,
            __('The dispute on :number was decided', ['number' => $order->number]),
            $description,
            $this->sellerUrl($order));
    }

    private function item(Order $order): string
    {
        return (string) Auction::query()->whereKey($order->auction_id)->value('title');
    }

    private function buyerUrl(Order $order): string
    {
        return route('orders.show', $order);
    }

    private function sellerUrl(Order $order): string
    {
        return OrderResource::getUrl('view', ['record' => $order], panel: 'seller', tenant: Company::query()->findOrFail($order->company_id));
    }
}
