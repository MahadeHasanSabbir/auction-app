<?php

namespace App\View\Components\Site;

use App\Models\User;
use App\Models\Wallet;
use App\Support\Home;
use App\Support\Money;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The site header. Loads what it shows for a signed-in user here, in two
 * small queries, so no page has to remember to pass it in.
 */
class Header extends Component
{
    public ?User $user;

    /** @var array{url: string, label: string}|null */
    public ?array $workspace = null;

    /** A buyer's spendable balance; null for everyone else. */
    public ?Money $balance = null;

    public int $unreadCount = 0;

    /** @var Collection<int, DatabaseNotification> */
    public Collection $notifications;

    public function __construct()
    {
        $this->user = Auth::user();
        $this->notifications = collect();

        if ($this->user === null) {
            return;
        }

        $this->workspace = Home::linkFor($this->user);

        if ($this->user->isBuyer()) {
            $this->balance = Wallet::query()->where('user_id', $this->user->id)->first()?->available();
        }

        $this->unreadCount = $this->user->unreadNotifications()->count();
        $this->notifications = $this->unreadCount === 0
            ? collect()
            : $this->user->unreadNotifications()->limit(5)->get();
    }

    public function render(): View
    {
        return view('components.site.header');
    }
}
