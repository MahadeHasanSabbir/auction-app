<?php

namespace App\Livewire\Messages;

use App\Actions\Messaging\MarkConversationRead;
use App\Actions\Messaging\PostOrderMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The chat about an order, between the buyer and the selling team. Used on the
 * buyer's order page and in the seller panel. New messages arrive over the
 * websocket; the seller panel does not load Echo, so it polls instead.
 */
class OrderChat extends Component
{
    #[Locked]
    public int $orderId;

    #[Locked]
    public bool $poll = false;

    public string $body = '';

    public function mount(int $orderId, bool $poll = false): void
    {
        $this->orderId = $orderId;
        $this->poll = $poll;

        Gate::forUser($this->user())->authorize('chat', $this->order());
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        // Where the page has no websocket client (the seller panel), it polls instead.
        if ($this->poll) {
            return [];
        }

        $id = Conversation::query()->where('order_id', $this->orderId)->value('id');

        return $id === null ? [] : ["echo-private:conversation.{$id},.message.posted" => 'incoming'];
    }

    /** Called when the page is shown: the person has now seen what is there. */
    public function markRead(MarkConversationRead $action): void
    {
        $conversation = $this->conversation();

        if ($conversation !== null) {
            $action->handle($this->user(), $conversation);
        }
    }

    public function send(PostOrderMessage $action): void
    {
        $action->handle($this->user(), $this->order(), $this->body);

        $this->reset('body');
        $this->dispatch('chat-updated');
    }

    public function incoming(): void
    {
        $this->markRead(app(MarkConversationRead::class));
        $this->dispatch('chat-updated');
    }

    public function render(): View
    {
        $conversation = $this->conversation();

        return view('livewire.messages.order-chat', [
            'messages' => $conversation === null
                ? collect()
                : Message::query()->where('conversation_id', $conversation->id)->with('author:id,name,role')->latest('id')->limit(100)->get()->reverse()->values(),
            'viewerId' => $this->user()->id,
            'order' => $this->order(),
        ]);
    }

    private function conversation(): ?Conversation
    {
        return Conversation::query()->where('order_id', $this->orderId)->first();
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
