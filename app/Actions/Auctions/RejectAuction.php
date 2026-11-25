<?php

namespace App\Actions\Auctions;

use App\Actions\Auctions\Concerns\AuctionRules;
use App\Enums\AuctionStatus;
use App\Enums\NotificationKind;
use App\Models\Auction;
use App\Models\User;
use App\Services\Notifications\AccountAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Platform staff send an auction back with a reason the seller sees; editing it makes it a draft again. */
final class RejectAuction
{
    use AuctionRules;

    public function handle(User $reviewer, Auction $auction, string $reason): Auction
    {
        Gate::forUser($reviewer)->authorize('review', $auction);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => __('Tell the seller what to change, in up to 1000 characters.')]);
        }

        return DB::transaction(function () use ($reviewer, $auction, $reason): Auction {
            $locked = Auction::query()->lockForUpdate()->findOrFail($auction->id);

            if ($locked->status !== AuctionStatus::PendingReview) {
                throw ValidationException::withMessages(['auction' => __('Only an auction awaiting review can be rejected.')]);
            }

            $locked->transitionTo(AuctionStatus::Rejected);
            $locked->forceFill([
                'rejection_reason' => $reason,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            $this->record($reviewer, $locked, 'rejected', ['reason' => $reason]);

            app(AccountAlerts::class)->listing($locked, NotificationKind::AuctionRejected, __('Reviewer note: :reason', ['reason' => $reason]));

            return $auction->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
