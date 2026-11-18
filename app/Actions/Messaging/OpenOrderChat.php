<?php

namespace App\Actions\Messaging;

use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The chat about an order: one per order, opened together with it (OpenOrder)
 * and created on first use for orders that predate it. A race between two
 * creators loses to the unique index and reads the winner.
 */
final class OpenOrderChat
{
    public function handle(Order $order): Conversation
    {
        $existing = Conversation::query()->where('order_id', $order->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $conversation = new Conversation;
            $conversation->forceFill([
                'type' => ConversationType::Order,
                'order_id' => $order->id,
                'company_id' => $order->company_id,
                'subject' => $order->number,
                'status' => ConversationStatus::Open,
                'opened_by' => $order->buyer_id,
            ])->save();

            return $conversation;
        } catch (UniqueConstraintViolationException) {
            return Conversation::query()->where('order_id', $order->id)->firstOrFail();
        }
    }
}
