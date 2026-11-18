<?php

namespace App\Livewire;

use App\Actions\Support\OpenTicket;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The public contact form. It opens a support ticket: for a signed-in user it
 * is theirs to follow in Support, for a visitor the answer comes by email.
 */
#[Layout('layouts.site')]
class Contact extends Component
{
    public string $name = '';

    public string $email = '';

    public string $subject = '';

    public string $body = '';

    /** A hidden field real people leave empty; bots fill every field. */
    public string $website = '';

    public ?int $sentTicket = null;

    public function mount(): void
    {
        $user = Auth::user();

        if ($user !== null) {
            $this->name = $user->name;
            $this->email = $user->email;
        }
    }

    public function send(OpenTicket $action): void
    {
        if ($this->website !== '') {
            // Looks like a bot: act as if it worked.
            $this->sentTicket = 0;

            return;
        }

        $ticket = $action->handle(Auth::user(), [
            'name' => $this->name,
            'email' => $this->email,
            'subject' => $this->subject,
            'body' => $this->body,
        ]);

        $this->reset('subject', 'body');
        $this->sentTicket = $ticket->id;
    }

    public function render(): View
    {
        return view('livewire.contact', ['signedIn' => Auth::check()])->title(__('Contact us'));
    }
}
