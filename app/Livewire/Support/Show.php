<?php

namespace App\Livewire\Support;

use App\Actions\Messaging\MarkConversationRead;
use App\Actions\Support\CloseTicket;
use App\Actions\Support\ReplyToTicket;
use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** One support ticket as the person who opened it sees it: the thread, a reply box and closing it. */
#[Layout('layouts.site')]
class Show extends Component
{
    #[Locked]
    public int $ticketId;

    public string $body = '';

    public function mount(Conversation $conversation): void
    {
        abort_unless($conversation->type === ConversationType::Support && $this->user()->can('view', $conversation), 404);

        $this->ticketId = $conversation->id;
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return ["echo-private:conversation.{$this->ticketId},.message.posted" => 'incoming'];
    }

    public function markRead(MarkConversationRead $action): void
    {
        $action->handle($this->user(), $this->ticket());
    }

    public function incoming(): void
    {
        $this->markRead(app(MarkConversationRead::class));
        $this->dispatch('chat-updated');
    }

    public function reply(ReplyToTicket $action): void
    {
        $action->handle($this->user(), $this->ticket(), $this->body);

        $this->reset('body');
        $this->dispatch('chat-updated');
    }

    public function close(CloseTicket $action): void
    {
        $action->handle($this->user(), $this->ticket());

        $this->dispatch('toast', message: __('Ticket closed. Write again any time to reopen it.'));
    }

    public function render(): View
    {
        $ticket = $this->ticket();

        return view('livewire.support.show', [
            'ticket' => $ticket,
            'isOpen' => $ticket->status === ConversationStatus::Open,
            'messages' => Message::query()->where('conversation_id', $ticket->id)->with('author:id,name,role')->orderBy('id')->limit(200)->get(),
            'viewerId' => $this->user()->id,
        ])->title(__('Ticket #:id', ['id' => $ticket->id]));
    }

    private function ticket(): Conversation
    {
        return Conversation::query()->findOrFail($this->ticketId);
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
