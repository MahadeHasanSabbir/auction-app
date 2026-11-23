<?php

namespace App\Livewire\Account;

use App\Actions\Notifications\UpdateNotificationPreferences;
use App\Enums\NotificationCategory;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Which kinds of notification reach the bell and which are also emailed. */
#[Layout('layouts.site')]
class NotificationSettings extends Component
{
    /** @var array<string, array{bell: bool, mail: bool, push: bool}> */
    public array $choices = [];

    public function mount(): void
    {
        $user = $this->user();

        foreach (NotificationCategory::cases() as $category) {
            $this->choices[$category->value] = [
                'bell' => $user->wantsNotification($category, 'bell'),
                'mail' => $user->wantsNotification($category, 'mail'),
                'push' => $user->wantsNotification($category, 'push'),
            ];
        }
    }

    public function save(UpdateNotificationPreferences $action): void
    {
        $action->handle($this->user(), $this->choices);

        $this->dispatch('toast', message: __('Notification settings saved.'));
    }

    public function render(): View
    {
        return view('livewire.account.notification-settings', [
            'categories' => NotificationCategory::cases(),
            'pushKey' => config('webpush.vapid.public_key'),
        ])
            ->title(__('Notification settings'));
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
