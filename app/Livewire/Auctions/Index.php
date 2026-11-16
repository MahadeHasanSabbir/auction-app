<?php

namespace App\Livewire\Auctions;

use App\Enums\AuctionStatus;
use App\Enums\CompanyStatus;
use App\Models\Auction;
use App\Models\Category;
use App\Models\Company;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use InvalidArgumentException;
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

    /** Which auctions each "status" filter value shows. */
    private const STATUSES = [
        'open' => [AuctionStatus::Live, AuctionStatus::Scheduled],
        'live' => [AuctionStatus::Live],
        'upcoming' => [AuctionStatus::Scheduled],
        'ended' => [AuctionStatus::Sold, AuctionStatus::Unsold],
    ];

    private const SORTS = ['ending', 'newest', 'price_low', 'price_high', 'bids'];

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

    /** Any change to a filter starts again from the first page. */
    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'category', 'company', 'minPrice', 'maxPrice', 'sort');
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.auctions.index', [
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
        // Unknown values in the URL fall back to the defaults.
        $statuses = self::STATUSES[$this->status] ?? self::STATUSES['open'];
        $sort = in_array($this->sort, self::SORTS, true) ? $this->sort : 'ending';

        $query = Auction::query()->forCards()->whereIn('status', $statuses);

        $search = trim($this->search);
        if ($search !== '') {
            // Typed % and _ are matched literally, not as wildcards.
            $like = '%'.addcslashes($search, '\\%_').'%';

            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', $like)
                ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', $like)));
        }

        if ($this->category !== '') {
            $ids = Category::query()
                ->where('slug', $this->category)
                ->orWhereHas('parent', fn (Builder $parent) => $parent->where('slug', $this->category))
                ->pluck('id');

            $query->whereHas('product', fn (Builder $product) => $product->whereIn('category_id', $ids));
        }

        if ($this->company !== '') {
            $query->whereHas('company', fn (Builder $company) => $company->where('slug', $this->company));
        }

        if (($min = $this->price($this->minPrice)) !== null) {
            $query->where('current_price', '>=', $min->amount);
        }

        if (($max = $this->price($this->maxPrice)) !== null) {
            $query->where('current_price', '<=', $max->amount);
        }

        return match ($sort) {
            'newest' => $query->latest('id'),
            'price_low' => $query->orderBy('current_price')->orderBy('id'),
            'price_high' => $query->orderByDesc('current_price')->orderBy('id'),
            'bids' => $query->orderByDesc('bid_count')->orderBy('id'),
            // Live before scheduled ("live" sorts first), then whatever ends
            // or starts soonest; finished auctions newest first.
            default => $this->status === 'ended'
                ? $query->orderByDesc('closed_at')->orderByDesc('id')
                : $query->orderBy('status')->orderByRaw('coalesce(ends_at, starts_at)')->orderBy('id'),
        };
    }

    /** A typed price as Money, or null when empty or not a valid amount. */
    private function price(string $typed): ?Money
    {
        $typed = trim(str_replace(',', '', $typed));

        if ($typed === '') {
            return null;
        }

        try {
            $money = Money::parse($typed);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $money->isNegative() ? null : $money;
    }
}
