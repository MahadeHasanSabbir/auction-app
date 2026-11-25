<?php

namespace App\Livewire\Support;

use App\Actions\Messaging\MarkConversationRead;
use App\Actions\Support\ReplyToTicket;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A support ticket's thread with a reply box, as platform staff see it in
 * the admin panel. The panel has no websocket client, so it polls.
 */
class StaffThread extends Component
{
    #[Locked]
    public int $ticketId;

    public string $body = '';

    public function mount(int $ticketId): void
    {
        $ticket = Conversation::query()->findOrFail($ticketId);

        abort_unless($ticket->type === ConversationType::Support && $this->user()->can('post', $ticket), 403);

        $this->ticketId = $ticketId;
    }

    public function markRead(MarkConversationRead $action): void
    {
        $action->handle($this->user(), $this->ticket());
    }

    public function reply(ReplyToTicket $action): void
    {
        $action->handle($this->user(), $this->ticket(), $this->body);

        $this->reset('body');
        $this->dispatch('chat-updated');
        // The page's status badge and actions follow a reopened ticket.
        $this->dispatch('ticket-updated');
    }

    public function render(): View
    {
        $ticket = $this->ticket();

        return view('livewire.support.staff-thread', [
            'ticket' => $ticket,
            'messages' => Message::query()->where('conversation_id', $ticket->id)->with('author:id,name,role')->orderBy('id')->limit(200)->get(),
            'viewerId' => $this->user()->id,
        ]);
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
