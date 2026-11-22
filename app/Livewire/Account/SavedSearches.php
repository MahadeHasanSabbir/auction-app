<?php

namespace App\Livewire\Account;

use App\Models\Category;
use App\Models\Company;
use App\Models\SavedSearch;
use App\Models\User;
use App\Support\Home;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** A buyer's saved searches: open one, switch its alerts, delete it. */
#[Layout('layouts.site')]
class SavedSearches extends Component
{
    public function mount(): void
    {
        $user = $this->user();

        if (! $user->isBuyer()) {
            $this->redirect(Home::pathFor($user));
        }
    }

    public function toggleAlerts(int $id): void
    {
        $search = $this->find($id);
        Gate::authorize('update', $search);

        $search->forceFill(['alerts' => ! $search->alerts])->save();

        $this->dispatch('toast', message: $search->alerts ? __('Alerts on for “:name”.', ['name' => $search->name]) : __('Alerts off for “:name”.', ['name' => $search->name]));
    }

    public function delete(int $id): void
    {
        $search = $this->find($id);
        Gate::authorize('delete', $search);

        $search->delete();

        $this->dispatch('toast', message: __('Saved search deleted.'));
    }

    public function render(): View
    {
        $searches = SavedSearch::query()->where('user_id', $this->user()->id)->latest('id')->get();
        $filters = $searches->map(fn (SavedSearch $search) => $search->criteria());

        return view('livewire.account.saved-searches', [
            'searches' => $searches,
            'max' => (int) config('auction.engagement.saved_searches_max'),
            'categoryNames' => Category::query()->whereIn('slug', $filters->pluck('category')->filter()->unique())->pluck('name', 'slug')->all(),
            'companyNames' => Company::query()->whereIn('slug', $filters->pluck('company')->filter()->unique())->pluck('name', 'slug')->all(),
        ])->title(__('Saved searches'));
    }

    private function find(int $id): SavedSearch
    {
        return SavedSearch::query()->where('user_id', $this->user()->id)->findOrFail($id);
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
