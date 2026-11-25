<?php

namespace App\Livewire\Auctions;

use App\Actions\Searches\SaveSearch;
use App\Enums\CompanyStatus;
use App\Models\Auction;
use App\Models\Category;
use App\Models\Company;
use App\Support\AuctionFilters;
use App\Support\AuctionListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public auction list: search, filters and sorting, all kept in the URL
 * so a filtered view can be bookmarked and shared.
 */
#[Layout('layouts.site')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'open')]
    public string $status = 'open';

    /** A category slug; a parent category includes its children. */
    #[Url(except: '')]
    public string $category = '';

    /** A company slug. */
    #[Url(except: '')]
    public string $company = '';

    /** Price bounds as typed, in major units ("1500" or "1500.50"). */
    #[Url(as: 'min', except: '')]
    public string $minPrice = '';

    #[Url(as: 'max', except: '')]
    public string $maxPrice = '';

    #[Url(except: 'ending')]
    public string $sort = 'ending';

    /** The name typed for a new saved search; empty takes a summary of the filters. */
    public string $searchName = '';

    /** Any change to a filter starts again from the first page. */
    public function updated(string $property): void
    {
        if ($property === 'searchName') {
            return;
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'category', 'company', 'minPrice', 'maxPrice', 'sort');
        $this->resetPage();
    }

    /** Saves the current filters (buyers only) so new matches are alerted. */
    public function saveSearch(SaveSearch $save): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->redirectRoute('login');

            return;
        }

        try {
            $save->handle($user, $this->filters(), $this->searchName);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->reset('searchName');
        $this->dispatch('search-saved');
        $this->dispatch('toast', message: __('Search saved. We will tell you about new auctions that match.'));
    }

    public function render(): View
    {
        $user = Auth::user();

        return view('livewire.auctions.index', [
            'canSaveSearch' => ($user === null || $user->isBuyer()) && ! $this->filters()->isEmpty(),
            'savedSearchName' => $this->filters()->summary(),
            'auctions' => $this->query()->paginate(12),
            'categories' => Category::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->with(['children' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->get(),
            'companies' => Company::query()
                ->where('status', CompanyStatus::Active)
                ->whereHas('auctions', fn (Builder $query) => $query->visibleToPublic())
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
            'hasFilters' => $this->search !== '' || $this->status !== 'open' || $this->category !== ''
                || $this->company !== '' || $this->minPrice !== '' || $this->maxPrice !== '',
        ])->title(__('Auctions'));
    }

    /** @return Builder<Auction> */
    private function query(): Builder
    {
        return AuctionListing::query($this->status, $this->sort, $this->filters());
    }

    public function filters(): AuctionFilters
    {
        return new AuctionFilters($this->search, $this->category, $this->company, $this->minPrice, $this->maxPrice);
    }
}
