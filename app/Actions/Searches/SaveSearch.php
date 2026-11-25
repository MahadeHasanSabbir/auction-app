<?php

namespace App\Actions\Searches;

use App\Models\SavedSearch;
use App\Models\User;
use App\Support\AuctionFilters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a buyer's filters on the auction list, with alerts on: a newly
 * approved auction that matches is announced to them (AlertSavedSearches).
 * The same filters saved twice are refused, and so is a search with no
 * filters at all (it would match everything).
 */
final class SaveSearch
{
    public function handle(User $buyer, AuctionFilters $filters, string $name = ''): SavedSearch
    {
        Gate::forUser($buyer)->authorize('create', SavedSearch::class);

        if ($filters->isEmpty()) {
            throw ValidationException::withMessages(['searchName' => __('Choose a search word, category, seller or price first.')]);
        }

        $name = trim($name) !== '' ? trim($name) : $filters->summary();

        if (mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['searchName' => __('Keep the name under 100 characters.')]);
        }

        return DB::transaction(function () use ($buyer, $filters, $name): SavedSearch {
            // The buyer's row serialises their saves, so the cap holds.
            User::query()->whereKey($buyer->id)->lockForUpdate()->value('id');

            $existing = SavedSearch::query()->where('user_id', $buyer->id)->get(['id', 'filters']);

            if ($existing->contains(fn (SavedSearch $search) => $search->criteria()->toArray() == $filters->toArray())) {
                throw ValidationException::withMessages(['searchName' => __('You have already saved this search.')]);
            }

            $max = (int) config('auction.engagement.saved_searches_max');

            if ($existing->count() >= $max) {
                throw ValidationException::withMessages(['searchName' => __('You can keep up to :max saved searches. Delete one first.', ['max' => $max])]);
            }

            $search = new SavedSearch(['name' => $name, 'filters' => $filters->toArray(), 'alerts' => true]);
            $search->user_id = $buyer->id;
            $search->save();

            return $search;
        });
    }
}
