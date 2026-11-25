<?php

namespace App\Livewire\Account;

use App\Enums\AuctionStatus;
use App\Enums\OrderStatus;
use App\Models\Auction;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Home;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A buyer's starting page: wallet at a glance and the auctions they are
 * bidding on. Staff and sellers work in their panels and are sent there.
 */
#[Layout('layouts.site')]
class Overview extends Component
{
    public function mount(): void
    {
        $user = $this->user();

        if (! $user->isBuyer()) {
            $this->redirect(Home::pathFor($user));
        }
    }

    public function render(): View
    {
        $user = $this->user();

        $wallet = Wallet::query()->where('user_id', $user->id)->first();

        // Live auctions this buyer has bid on, the ones ending soonest first.
        $bidding = Auction::query()
            ->where('status', AuctionStatus::Live)
            ->whereHas('bids', fn (Builder $query) => $query->where('user_id', $user->id))
            ->forCards()
            ->orderBy('ends_at')
            ->limit(6)
            ->get();

        return view('livewire.account.overview', [
            'user' => $user,
            'wallet' => $wallet,
            'biddable' => $wallet?->available()->basisPointsFloor((int) config('auction.bidding.biddable_share_bps')),
            'bidding' => $bidding,
            'stats' => [
                'leading' => Auction::query()->where('status', AuctionStatus::Live)->where('leader_id', $user->id)->count(),
                'watching' => $user->watchedAuctions()->whereIn('status', [AuctionStatus::Live, AuctionStatus::Scheduled])->count(),
                'won' => Auction::query()->where('status', AuctionStatus::Sold)->where('winner_id', $user->id)->count(),
                'openOrders' => Order::query()->where('buyer_id', $user->id)->whereIn('status', [OrderStatus::InEscrow, OrderStatus::Shipped])->count(),
            ],
        ])->title(__('My account'));
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
