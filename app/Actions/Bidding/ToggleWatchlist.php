<?php

namespace App\Actions\Bidding;

use App\Models\Auction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** A buyer adds an auction to their watchlist, or takes it off. Returns whether it is watched now. */
final class ToggleWatchlist
{
    public function handle(User $buyer, Auction $auction): bool
    {
        Gate::forUser($buyer)->authorize('bid', $auction);

        if (! $auction->status->isPublic()) {
            throw ValidationException::withMessages(['auction' => __('This auction is not available.')]);
        }

        if ($buyer->watchedAuctions()->whereKey($auction->id)->exists()) {
            $buyer->watchedAuctions()->detach($auction->id);

            return false;
        }

        // insertOrIgnore: a double click must not fail on the unique index.
        DB::table('watchlists')->insertOrIgnore([
            'user_id' => $buyer->id,
            'auction_id' => $auction->id,
            'created_at' => now(),
        ]);

        return true;
    }
}
