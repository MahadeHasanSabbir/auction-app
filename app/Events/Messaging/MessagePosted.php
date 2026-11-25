<?php

namespace App\Events\Messaging;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was added to a conversation. It only tells the open chat pages to
 * reload (they read the messages themselves, with their own authorisation),
 * so nothing private travels in it. Channel: `conversation.{id}`, open to the
 * people ConversationPolicy::view allows.
 */
final class MessagePosted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $conversationId, public int $messageId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('conversation.'.$this->conversationId);
    }

    public function broadcastAs(): string
    {
        return 'message.posted';
    }

    public function broadcastQueue(): string
    {
        return (string) config('auction.realtime.queue');
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return ['message_id' => $this->messageId];
    }
}
