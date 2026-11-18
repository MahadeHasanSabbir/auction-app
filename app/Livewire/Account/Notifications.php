<?php

namespace App\Livewire\Account;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Everything the platform told the signed-in user, newest first. */
#[Layout('layouts.site')]
class Notifications extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'all';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    /** Marks one notification read and goes to the page it is about. */
    public function open(string $id): void
    {
        $notification = $this->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        $path = (string) ($notification->data['url'] ?? '');

        // Only ever a path on this site.
        $this->redirect(str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : route('notifications'), navigate: false);
    }

    public function markAllRead(): void
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.account.notifications', [
            'notifications' => ($this->filter === 'unread' ? $user->unreadNotifications() : $user->notifications())->latest()->paginate(15),
            'unread' => $user->unreadNotifications()->count(),
        ])->title(__('Notifications'));
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
