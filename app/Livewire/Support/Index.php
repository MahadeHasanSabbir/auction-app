<?php

namespace App\Livewire\Support;

use App\Actions\Support\OpenTicket;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** The signed-in user's support tickets, and a form to open a new one. */
#[Layout('layouts.site')]
class Index extends Component
{
    public bool $opening = false;

    public string $subject = '';

    public string $body = '';

    public function mount(): void
    {
        $this->opening = ! Conversation::query()->where('type', ConversationType::Support)->where('opened_by', $this->user()->id)->exists();
    }

    public function open(OpenTicket $action): void
    {
        $ticket = $action->handle($this->user(), ['subject' => $this->subject, 'body' => $this->body]);

        $this->redirect(route('support.show', $ticket));
    }

    public function render(): View
    {
        return view('livewire.support.index', [
            'tickets' => Conversation::query()
                ->where('type', ConversationType::Support)
                ->where('opened_by', $this->user()->id)
                ->latest('last_message_at')
                ->limit(50)
                ->get(),
        ])->title(__('Support'));
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
