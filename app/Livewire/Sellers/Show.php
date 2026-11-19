<?php

namespace App\Livewire\Sellers;

use App\Enums\AuctionStatus;
use App\Enums\CompanyStatus;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Review;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A seller's public page: who they are, how they have done, and their
 * auctions (open now and coming up, or sold). Contact details stay private;
 * buyers and sellers talk through the order once something is sold.
 */
#[Layout('layouts.site')]
class Show extends Component
{
    use WithPagination;

    #[Locked]
    public int $companyId;

    #[Url]
    public string $tab = 'open';

    public function mount(Company $company): void
    {
        // A company that has not been approved has nothing to show yet.
        abort_if($company->status === CompanyStatus::Pending, 404);

        $this->companyId = $company->id;
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $company = Company::query()->findOrFail($this->companyId);

        if (! in_array($this->tab, ['open', 'sold', 'reviews'], true)) {
            $this->tab = 'open';
        }

        $counts = Auction::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [AuctionStatus::Live, AuctionStatus::Scheduled, AuctionStatus::Sold])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('livewire.sellers.show', [
            'company' => $company,
            'auctions' => $this->tab === 'reviews' ? null : $this->auctions($company),
            'reviews' => $this->tab === 'reviews' ? $this->reviews($company) : null,
            'live' => (int) ($counts[AuctionStatus::Live->value] ?? 0),
            'upcoming' => (int) ($counts[AuctionStatus::Scheduled->value] ?? 0),
            'sold' => (int) ($counts[AuctionStatus::Sold->value] ?? 0),
        ])->title($company->name)->layoutData([
            'description' => $company->about ?: __(':name sells on :site. See their live and upcoming auctions.', ['name' => $company->name, 'site' => config('app.name')]),
            'image' => $company->banner_path ? Storage::disk('public')->url($company->banner_path) : ($company->logo_path ? Storage::disk('public')->url($company->logo_path) : null),
            'canonical' => route('sellers.show', $company),
        ]);
    }

    /** @return LengthAwarePaginator<int, Review> */
    private function reviews(Company $company): LengthAwarePaginator
    {
        return Review::query()
            ->where('company_id', $company->id)
            ->where('is_visible', true)
            ->with(['buyer' => fn ($query) => $query->withTrashed()->select('id', 'name')])
            ->latest()
            ->paginate(10);
    }

    /** @return LengthAwarePaginator<int, Auction> */
    private function auctions(Company $company): LengthAwarePaginator
    {
        $query = Auction::query()->where('company_id', $company->id)->forCards();

        if ($this->tab === 'sold') {
            return $query->where('status', AuctionStatus::Sold)->latest('closed_at')->paginate(12);
        }

        // Live first, ending soonest; then the scheduled ones, opening soonest.
        return $query->whereIn('status', [AuctionStatus::Live, AuctionStatus::Scheduled])
            ->orderByRaw('status = ? desc', [AuctionStatus::Live->value])
            ->orderByRaw('coalesce(case when status = ? then ends_at end, starts_at)', [AuctionStatus::Live->value])
            ->paginate(12);
    }
}
