<?php

namespace App\Actions\Auctions;

use App\Actions\Auctions\Concerns\AuctionRules;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** The seller takes an auction back from review to change it; it becomes a draft again. */
final class WithdrawAuction
{
    use AuctionRules;

    public function handle(User $user, Auction $auction): Auction
    {
        Gate::forUser($user)->authorize('submit', $auction);

        return DB::transaction(function () use ($user, $auction): Auction {
            $locked = Auction::query()->lockForUpdate()->findOrFail($auction->id);

            if ($locked->status !== AuctionStatus::PendingReview) {
                throw ValidationException::withMessages(['auction' => __('Only an auction awaiting review can be taken back.')]);
            }

            $locked->transitionTo(AuctionStatus::Draft);
            $locked->forceFill(['submitted_at' => null])->save();

            $this->record($user, $locked, 'withdrawn');

            return $auction->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
