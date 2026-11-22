<?php

namespace App\Livewire\Orders;

use App\Actions\Orders\ApplyOrderCoupon;
use App\Actions\Orders\CompleteOrder;
use App\Actions\Orders\LeaveReview;
use App\Actions\Orders\OpenDispute;
use App\Actions\Orders\UpdateDeliveryAddress;
use App\Actions\Orders\WithdrawDispute;
use App\Enums\DisputeReason;
use App\Enums\InvoiceKind;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The buyer's order page: where the order stands, the delivery address,
 * tracking, confirming delivery, reporting a problem and the review.
 */
#[Layout('layouts.site')]
class Show extends Component
{
    #[Locked]
    public int $orderId;

    public bool $editingAddress = false;

    public string $shipping_name = '';

    public string $shipping_phone = '';

    public string $shipping_address = '';

    public bool $disputing = false;

    public string $reason = '';

    public string $description = '';

    public int $rating = 0;

    public string $comment = '';

    public string $couponCode = '';

    public function mount(Order $order): void
    {
        // The seller's team and staff work on orders in their panels.
        abort_unless($order->buyer_id === $this->user()->id, 404);

        $this->orderId = $order->id;
        $this->fillAddress($order);
        $this->editingAddress = blank($order->shipping_address);
    }

    public function applyCoupon(ApplyOrderCoupon $action): void
    {
        $this->attempt(function () use ($action): void {
            $action->handle($this->user(), $this->order(), $this->couponCode);
            $this->reset('couponCode');
        }, __('Coupon applied. The discount is back in your wallet.'));
    }

    public function saveAddress(UpdateDeliveryAddress $action): void
    {
        $this->attempt(function () use ($action): void {
            $order = $action->handle($this->user(), $this->order(), [
                'shipping_name' => $this->shipping_name,
                'shipping_phone' => $this->shipping_phone,
                'shipping_address' => $this->shipping_address,
            ]);
            $this->fillAddress($order);
            $this->editingAddress = false;
        }, __('Delivery address saved.'));
    }

    public function confirmDelivery(CompleteOrder $action): void
    {
        $this->attempt(fn () => $action->confirm($this->user(), $this->order()), __('Thanks! The payment has been released to the seller.'));
    }

    public function openDispute(OpenDispute $action): void
    {
        $this->attempt(function () use ($action): void {
            $action->handle($this->user(), $this->order(), ['reason' => $this->reason, 'description' => $this->description]);
            $this->reset('disputing', 'reason', 'description');
        }, __('Your dispute is open. Our team will look at it and contact you.'));
    }

    public function withdrawDispute(WithdrawDispute $action): void
    {
        $this->attempt(fn () => $action->handle($this->user(), $this->order()), __('Dispute withdrawn.'));
    }

    public function leaveReview(LeaveReview $action): void
    {
        $this->attempt(function () use ($action): void {
            $action->handle($this->user(), $this->order(), ['rating' => $this->rating, 'comment' => $this->comment]);
            $this->reset('rating', 'comment');
        }, __('Thanks for your review.'));
    }

    public function render(): View
    {
        $order = $this->order()->load([
            'auction:id,title,product_id',
            'auction.product:id',
            'auction.product.coverImage',
            'company:id,name,slug',
            'shipment',
            'events',
            'latestDispute',
            'review',
        ]);
        $user = $this->user();

        return view('livewire.orders.show', [
            'order' => $order,
            'reasons' => DisputeReason::cases(),
            'receipt' => Invoice::query()->where('kind', InvoiceKind::Purchase)->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)->first(),
            'can' => [
                'address' => $user->can('updateAddress', $order),
                'coupon' => $user->can('applyCoupon', $order),
                'confirm' => $user->can('confirm', $order),
                'dispute' => $user->can('dispute', $order),
                'withdraw' => $user->can('withdrawDispute', $order),
                'review' => $user->can('review', $order) && $order->review === null,
            ],
        ])->title(__('Order :number', ['number' => $order->number]));
    }

    /** Runs an action; a refusal about the order itself becomes a toast, field errors stay on the form. */
    private function attempt(Closure $callback, string $success): void
    {
        $this->resetValidation();

        try {
            $callback();
        } catch (ValidationException $e) {
            if (array_key_exists('order', $e->errors())) {
                $this->dispatch('toast', message: $e->validator->errors()->first('order'), type: 'error');

                return;
            }

            throw $e;
        }

        $this->dispatch('toast', message: $success);
    }

    private function fillAddress(Order $order): void
    {
        $this->shipping_name = (string) $order->shipping_name;
        $this->shipping_phone = (string) $order->shipping_phone;
        $this->shipping_address = (string) $order->shipping_address;
    }

    private function order(): Order
    {
        return Order::query()->findOrFail($this->orderId);
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
