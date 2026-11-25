<?php

namespace App\Actions\Auctions;

use App\Actions\Orders\OpenOrder;
use App\Enums\AuctionCloseReason;
use App\Enums\AuctionStatus;
use App\Enums\HoldType;
use App\Enums\NotificationKind;
use App\Events\Account\AuctionWon;
use App\Events\Auctions\AuctionClosed;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\User;
use App\Models\WalletHold;
use App\Services\Ledger\Ledger;
use App\Services\Notifications\AccountAlerts;
use Illuminate\Support\Facades\DB;

/**
 * Ends a live auction whose end time has passed. Re-checks everything under
 * the row lock, so a late anti-sniping extension or a second closer does
 * nothing: an auction is closed once and gets at most one order.
 *
 * With bids and the reserve met, the leader wins at the current price,
 * which is captured from their hold into escrow. Otherwise it ends unsold
 * and the leader's hold is released.
 */
final class CloseAuction
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly OpenOrder $openOrder,
    ) {}

    /** @return bool whether this call closed it */
    public function handle(Auction $auction): bool
    {
        return DB::transaction(function () use ($auction): bool {
            $locked = Auction::query()->lockForUpdate()->find($auction->id);

            if ($locked === null || $locked->status !== AuctionStatus::Live || $locked->ends_at === null || now()->lessThan($locked->ends_at)) {
                return false;
            }

            $hold = WalletHold::query()->active()->where('auction_id', $locked->id)->where('type', HoldType::Bid)->first();
            $leader = $locked->leader_id === null ? null : User::query()->find($locked->leader_id);

            if ($leader !== null && $locked->bid_count > 0 && $locked->reserve_met) {
                $locked->transitionTo(AuctionStatus::Sold);
                $locked->forceFill([
                    'close_reason' => AuctionCloseReason::Sold,
                    'winner_id' => $leader->id,
                    'final_price' => $locked->current_price,
                    'closed_at' => now(),
                ])->save();

                $order = $this->openOrder->handle($locked, $leader, $locked->current_price, $hold, 'Auction won; payment is held in escrow.');

                AuctionWon::dispatch($locked, $order);
            } else {
                $locked->transitionTo(AuctionStatus::Unsold);
                $locked->forceFill([
                    'close_reason' => $locked->leader_id === null ? AuctionCloseReason::NoBids : AuctionCloseReason::ReserveNotMet,
                    'closed_at' => now(),
                ])->save();

                if ($hold !== null) {
                    $this->ledger->release($hold);
                }

                app(AccountAlerts::class)->listing($locked, NotificationKind::AuctionUnsold, $locked->leader_id === null
                    ? __('Nobody bid. You can list the product again.')
                    : __('The reserve price was not met. You can list the product again.'));
            }

            AutoBid::query()->where('auction_id', $locked->id)->update(['is_active' => false]);

            AuctionClosed::dispatch($locked);

            $auction->setRawAttributes($locked->getAttributes(), true);

            return true;
        }, attempts: 3);
    }
}
