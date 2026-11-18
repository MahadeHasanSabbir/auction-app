<?php

namespace App\Livewire\Account;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\User;
use App\Support\Home;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A buyer's auctions: the ones they are bidding on, won, lost, and their
 * watchlist. Their own automatic maximum is shown to them only.
 */
#[Layout('layouts.site')]
class Bids extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'active';

    public function mount(): void
    {
        $user = $this->user();

        if (! $user->isBuyer()) {
            $this->redirect(Home::pathFor($user));
        }

        if (! in_array($this->tab, ['active', 'won', 'lost', 'watching'], true)) {
            $this->tab = 'active';
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    /** Sent by the live account listener when the viewer's wallet changes (a bid, being outbid, a payment). */
    #[On('wallet-updated')]
    public function walletUpdated(): void
    {
        // Nothing to do: the call itself re-renders the figures.
    }

    public function render(): View
    {
        $user = $this->user();
        $mine = fn (Builder $query) => $query->where('user_id', $user->id);

        $query = match ($this->tab) {
            'won' => Auction::query()->where('status', AuctionStatus::Sold)->where('winner_id', $user->id)->with('order:id,auction_id,number')->orderByDesc('closed_at'),
            'lost' => Auction::query()
                ->whereIn('status', [AuctionStatus::Sold, AuctionStatus::Unsold, AuctionStatus::Cancelled])
                ->whereHas('bids', $mine)
                ->where(fn (Builder $q) => $q->whereNull('winner_id')->orWhere('winner_id', '!=', $user->id))
                ->orderByDesc('closed_at'),
            'watching' => Auction::query()
                ->join('watchlists', 'watchlists.auction_id', '=', 'auctions.id')
                ->where('watchlists.user_id', $user->id)
                ->select('auctions.*')
                ->orderByDesc('watchlists.created_at'),
            default => Auction::query()->where('status', AuctionStatus::Live)->whereHas('bids', $mine)->orderBy('ends_at'),
        };

        $auctions = $query->forCards()
            ->withMax(['bids as my_top_bid' => $mine], 'amount')
            ->paginate(10);

        $maximums = AutoBid::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('auction_id', $auctions->pluck('id'))
            ->pluck('max_amount', 'auction_id');

        return view('livewire.account.bids', [
            'user' => $user,
            'auctions' => $auctions,
            'maximums' => $maximums,
            'tabs' => [
                'active' => __('Bidding'),
                'won' => __('Won'),
                'lost' => __('Not won'),
                'watching' => __('Watchlist'),
            ],
        ])->title(__('My bids'));
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
