<?php

namespace App\Livewire\Account;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\Home;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** A buyer's orders: the auctions they won or bought, with where each one stands. */
#[Layout('layouts.site')]
class Orders extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'open';

    public function mount(): void
    {
        $user = $this->user();

        if (! $user->isBuyer()) {
            $this->redirect(Home::pathFor($user));
        }

        if (! in_array($this->tab, ['open', 'closed'], true)) {
            $this->tab = 'open';
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $open = [OrderStatus::InEscrow, OrderStatus::Shipped, OrderStatus::Disputed];

        $orders = Order::query()
            ->where('buyer_id', $this->user()->id)
            ->when($this->tab === 'open', fn ($query) => $query->whereIn('status', $open), fn ($query) => $query->whereNotIn('status', $open))
            ->with(['auction:id,title,product_id', 'auction.product:id', 'auction.product.coverImage', 'company:id,name,slug'])
            ->latest('id')
            ->paginate(10);

        return view('livewire.account.orders', [
            'orders' => $orders,
            'tabs' => ['open' => __('In progress'), 'closed' => __('Finished')],
        ])->title(__('My orders'));
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
