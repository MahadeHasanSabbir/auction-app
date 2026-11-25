<?php

namespace App\Actions\Auctions;

use App\Actions\Auctions\Concerns\AuctionRules;
use App\Enums\AuctionCloseReason;
use App\Enums\AuctionStatus;
use App\Enums\HoldType;
use App\Events\Auctions\AuctionClosed;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\User;
use App\Models\WalletHold;
use App\Services\Ledger\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an auction that has not finished. The seller's team may cancel a
 * draft, a rejected one or one under review; an approved (scheduled) one
 * only the company owner, and a live one the owner only while nobody has
 * bid (AuctionPolicy::cancel). Platform staff cancel one under review,
 * scheduled or live, with a reason. Cancelling a live auction releases the
 * leader's hold.
 */
final class CancelAuction
{
    use AuctionRules;

    public function __construct(private readonly Ledger $ledger) {}

    public function handle(User $user, Auction $auction, ?string $reason = null): Auction
    {
        $byPlatform = $user->isPlatform();
        $reason = filled($reason) ? trim((string) $reason) : null;

        if ($byPlatform && ($reason === null || mb_strlen($reason) > 1000)) {
            throw ValidationException::withMessages(['reason' => __('Give the seller a reason, in up to 1000 characters.')]);
        }

        return DB::transaction(function () use ($user, $auction, $byPlatform, $reason): Auction {
            $locked = Auction::query()->lockForUpdate()->findOrFail($auction->id);

            // Checked on the locked row: whether the owner is needed depends on its status.
            Gate::forUser($user)->authorize($byPlatform ? 'review' : 'cancel', $locked);

            $allowed = $byPlatform
                ? [AuctionStatus::PendingReview, AuctionStatus::Scheduled, AuctionStatus::Live]
                : [AuctionStatus::Draft, AuctionStatus::PendingReview, AuctionStatus::Rejected, AuctionStatus::Scheduled, AuctionStatus::Live];

            if (! in_array($locked->status, $allowed, true)) {
                throw ValidationException::withMessages(['auction' => __('This auction cannot be cancelled.')]);
            }

            // The leader's money is no longer set aside; their maximum lapses.
            if ($locked->status === AuctionStatus::Live) {
                $hold = WalletHold::query()->active()->where('auction_id', $locked->id)->where('type', HoldType::Bid)->first();

                if ($hold !== null) {
                    $this->ledger->release($hold);
                }

                AutoBid::query()->where('auction_id', $locked->id)->update(['is_active' => false]);
            }

            $wasPublic = $locked->status->isPublic();

            $locked->transitionTo(AuctionStatus::Cancelled);
            $locked->forceFill([
                'close_reason' => $byPlatform ? AuctionCloseReason::CancelledByPlatform : AuctionCloseReason::CancelledBySeller,
                'cancellation_reason' => $reason,
                'closed_at' => now(),
            ])->save();

            $this->record($user, $locked, 'cancelled', ['reason' => $reason]);

            // Open pages of a scheduled or live auction learn it is gone.
            if ($wasPublic) {
                AuctionClosed::dispatch($locked);
            }

            return $auction->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
