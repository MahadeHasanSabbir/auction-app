<?php

namespace App\Livewire\Auctions;

use App\Models\Auction;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One auction: pictures, description, price, time left and the bid history.
 *
 * Read-only for now. Placing bids, the watchlist and live updates are added
 * with the auction engine and real-time phases.
 */
#[Layout('layouts.site')]
class Show extends Component
{
    #[Locked]
    public int $auctionId;

    public function mount(Auction $auction): void
    {
        // Drafts, rejected and cancelled auctions do not exist for the public.
        abort_unless($auction->status->isPublic(), 404);

        $this->auctionId = $auction->id;
    }

    public function render(): View
    {
        $auction = Auction::query()
            ->with([
                'company:id,name,slug,about,rating_sum,rating_count,verified_at',
                'product' => fn ($query) => $query->withTrashed(),
                'product.images',
                'product.category:id,name,slug,parent_id',
                'product.category.parent:id,name,slug',
                'winner:id,name',
            ])
            ->findOrFail($this->auctionId);

        abort_unless($auction->status->isPublic(), 404);

        return view('livewire.auctions.show', [
            'auction' => $auction,
            'bids' => $auction->bids()
                ->with('bidder:id,name')
                ->orderByDesc('seq')
                ->limit(15)
                ->get(),
            'viewerId' => auth()->id(),
        ])->title($auction->title);
    }
}
