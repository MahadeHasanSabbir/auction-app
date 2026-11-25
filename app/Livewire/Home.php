<?php

namespace App\Livewire;

use App\Enums\AuctionStatus;
use App\Enums\CompanyStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Category;
use App\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.site')]
class Home extends Component
{
    public function render(): View
    {
        return view('livewire.home', [
            'featured' => Auction::query()
                ->whereIn('status', [AuctionStatus::Live, AuctionStatus::Scheduled])
                ->whereNotNull('featured_at')
                ->forCards()
                ->orderBy('status')
                ->orderByRaw('coalesce(ends_at, starts_at)')
                ->limit((int) config('auction.engagement.home_featured'))
                ->get(),

            'endingSoon' => Auction::query()
                ->where('status', AuctionStatus::Live)
                ->forCards()
                ->orderBy('ends_at')
                ->limit(4)
                ->get(),

            'openingSoon' => Auction::query()
                ->where('status', AuctionStatus::Scheduled)
                ->forCards()
                ->orderBy('starts_at')
                ->limit(4)
                ->get(),

            'categories' => Category::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug']),

            // Headline numbers; a minute old at most.
            'stats' => Cache::remember('home.stats', 60, fn (): array => [
                'live' => Auction::query()->where('status', AuctionStatus::Live)->count(),
                'sellers' => Company::query()->where('status', CompanyStatus::Active)->count(),
                'bids' => Bid::query()->count(),
            ]),
        ]);
    }
}
