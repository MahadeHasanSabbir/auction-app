<?php

namespace App\Policies;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\User;

/**
 * An order chat belongs to the buyer and the selling company's team (platform
 * staff may read it, for disputes); a support ticket belongs to whoever opened
 * it and to platform staff. Starting an order chat is OrderPolicy::chat.
 */
final class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isPlatform()) {
            return true;
        }

        return match ($conversation->type) {
            ConversationType::Order => $this->takesPartInOrderChat($user, $conversation),
            ConversationType::Support => $conversation->opened_by !== null && $conversation->opened_by === $user->id,
        };
    }

    public function post(User $user, Conversation $conversation): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return match ($conversation->type) {
            ConversationType::Order => $this->takesPartInOrderChat($user, $conversation),
            ConversationType::Support => $user->isPlatform() || ($conversation->opened_by !== null && $conversation->opened_by === $user->id),
        };
    }

    /** The requester closes their own ticket; staff close any. */
    public function close(User $user, Conversation $conversation): bool
    {
        return $conversation->type === ConversationType::Support && $this->post($user, $conversation);
    }

    /** The admin panel's ticket desk. */
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isPlatform();
    }

    public function assign(User $user, Conversation $conversation): bool
    {
        return $user->is_active && $user->isPlatform() && $conversation->type === ConversationType::Support;
    }

    private function takesPartInOrderChat(User $user, Conversation $conversation): bool
    {
        if ($user->isSeller()) {
            return $user->company_id !== null && $user->company_id === $conversation->company_id;
        }

        return $user->isBuyer() && $conversation->order_id !== null
            && Order::query()->whereKey($conversation->order_id)->where('buyer_id', $user->id)->exists();
    }
}
