<?php

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

/**
 * The buyer and the selling company's team see an order; the team ships it,
 * only the owner cancels it; the buyer gives the address, confirms delivery,
 * disputes and reviews; platform staff resolve disputes. The status checks
 * here decide what is offered; the actions check again under the row lock.
 */
final class OrderPolicy
{
    /** Order lists: the seller panel shows the company's own, the admin panel every order. */
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isPlatform() || $user->isSeller());
    }

    public function view(User $user, Order $order): bool
    {
        return $user->is_active && ($this->isBuyer($user, $order) || $this->onTeam($user, $order) || $user->isPlatform());
    }

    public function updateAddress(User $user, Order $order): bool
    {
        return $this->isBuyer($user, $order) && $order->status === OrderStatus::InEscrow;
    }

    /** Use a seller coupon, once, before the order ships. */
    public function applyCoupon(User $user, Order $order): bool
    {
        return $this->isBuyer($user, $order) && $order->status === OrderStatus::InEscrow && $order->coupon_id === null;
    }

    public function ship(User $user, Order $order): bool
    {
        return $this->onTeam($user, $order) && $order->status === OrderStatus::InEscrow;
    }

    public function cancel(User $user, Order $order): bool
    {
        return $this->onTeam($user, $order) && $user->role === UserRole::CompanyOwner && $order->status === OrderStatus::InEscrow;
    }

    public function confirm(User $user, Order $order): bool
    {
        return $this->isBuyer($user, $order) && $order->status === OrderStatus::Shipped;
    }

    public function dispute(User $user, Order $order): bool
    {
        return $this->isBuyer($user, $order)
            && $order->status === OrderStatus::Shipped
            && ($order->auto_release_at === null || now()->lessThan($order->auto_release_at));
    }

    public function withdrawDispute(User $user, Order $order): bool
    {
        return $this->isBuyer($user, $order) && $order->status === OrderStatus::Disputed;
    }

    public function review(User $user, Order $order): bool
    {
        return $this->isBuyer($user, $order)
            && $order->status === OrderStatus::Completed
            && $order->completed_at !== null
            && now()->lessThan($order->completed_at->copy()->addDays((int) config('auction.orders.review_days')));
    }

    public function reply(User $user, Order $order): bool
    {
        return $this->onTeam($user, $order);
    }

    /** Buyer and the selling team talk about the order; staff can read it (see ConversationPolicy) but join through tickets. */
    public function chat(User $user, Order $order): bool
    {
        return $this->isBuyer($user, $order) || $this->onTeam($user, $order);
    }

    public function resolve(User $user, Order $order): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    private function isBuyer(User $user, Order $order): bool
    {
        return $user->is_active && $user->isBuyer() && $user->id === $order->buyer_id;
    }

    private function onTeam(User $user, Order $order): bool
    {
        return $user->is_active && $user->isSeller() && $user->company_id === $order->company_id;
    }
}
