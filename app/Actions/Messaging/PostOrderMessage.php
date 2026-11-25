<?php

namespace App\Actions\Messaging;

use App\Actions\Messaging\Concerns\PostsMessages;
use App\Enums\NotificationKind;
use App\Filament\Seller\Resources\Orders\OrderResource;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A message in the chat about an order, between the buyer and the selling
 * company's team. The other side
 * is told once until they have read it.
 */
final class PostOrderMessage
{
    use PostsMessages;

    public function __construct(private readonly Notifier $notifier, private readonly OpenOrderChat $chat) {}

    public function handle(User $author, Order $order, string $body): Message
    {
        Gate::forUser($author)->authorize('chat', $order);

        $body = $this->cleanBody($body, (int) config('auction.messaging.message_max'));
        $this->throttle($author);

        $message = DB::transaction(function () use ($author, $order, $body): Message {
            $conversation = $this->chat->handle($order);

            return $this->append($conversation, $author, $body);
        });

        $this->tellTheOtherSide($author, $order, $message->conversation_id);

        return $message;
    }

    private function tellTheOtherSide(User $author, Order $order, int $conversationId): void
    {
        $conversation = Conversation::query()->findOrFail($conversationId);
        $item = (string) Auction::query()->whereKey($order->auction_id)->value('title');

        $recipients = $author->isBuyer()
            ? $this->notifier->team($order->company_id)
            : User::query()->where('id', $order->buyer_id)->where('is_active', true)->get();

        $url = $author->isBuyer()
            ? OrderResource::getUrl('view', ['record' => $order], panel: 'seller', tenant: Company::query()->findOrFail($order->company_id))
            : route('orders.show', $order);

        foreach ($recipients as $recipient) {
            if ($recipient->id === $author->id || ! $this->shouldNotify($conversation, $recipient)) {
                continue;
            }

            $this->notifier->send($recipient, NotificationKind::ChatMessage,
                __('New message about :number', ['number' => $order->number]),
                __(':name wrote about “:item”.', ['name' => $author->isBuyer() ? $author->maskedName() : $author->name, 'item' => $item]),
                $url);
        }
    }
}
