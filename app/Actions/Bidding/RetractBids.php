<?php

namespace App\Actions\Bidding;

use App\Actions\Trust\IssueStrike;
use App\Enums\AuctionStatus;
use App\Enums\HoldType;
use App\Enums\StrikeSource;
use App\Events\Auctions\BidPlaced;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\Bid;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Services\Ledger\Ledger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A buyer takes back every bid of theirs on a live auction: only within
 * auction.trust.retract_window_minutes of their latest bid, never in the
 * auction's last auction.trust.retract_closing_minutes. Each retraction is
 * a strike.
 *
 * The bids keep their place in the sequence but stop standing. When the
 * buyer led, the lead goes to the highest remaining bid (the earlier one on
 * a tie) at that bid's amount, provided its bidder can still cover it; the
 * new leader's whole bid is held, as always. With nobody left the auction
 * is back at its starting price. When the buyer did not lead, the leader
 * and the price stay as they are.
 *
 * Lock order as for a bid: the auction row, the wallets, the bid hold.
 */
final class RetractBids
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly IssueStrike $strikes,
    ) {}

    public function handle(User $buyer, Auction $auction, string $reason): Auction
    {
        Gate::forUser($buyer)->authorize('bid', $auction);

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Tell us why, in a few words.')]);
        }

        $window = (int) config('auction.trust.retract_window_minutes');

        if ($window === 0) {
            throw ValidationException::withMessages(['reason' => __('Bids cannot be taken back on this marketplace.')]);
        }

        return DB::transaction(function () use ($buyer, $auction, $reason, $window): Auction {
            $locked = Auction::query()->lockForUpdate()->findOrFail($auction->id);
            $this->check($locked, $buyer, $window);

            $wasLeader = $locked->leader_id === $buyer->id;

            Bid::query()->where('auction_id', $locked->id)->where('user_id', $buyer->id)->standing()->update(['retracted_at' => now()]);
            AutoBid::query()->where('auction_id', $locked->id)->where('user_id', $buyer->id)->update(['is_active' => false]);

            if ($wasLeader) {
                $this->handOver($locked, $buyer);
            }

            $this->strikes->automatic($buyer, StrikeSource::Retraction,
                __('Took back bids on “:title”: :reason', ['title' => $locked->title, 'reason' => $reason]), $locked);

            activity('auctions')
                ->causedBy($buyer)
                ->performedOn($locked)
                ->event('bids_retracted')
                ->withProperties(['reason' => $reason, 'was_leader' => $wasLeader])
                ->log("bids_retracted auction {$locked->id}");

            BidPlaced::dispatch($locked);

            $auction->setRawAttributes($locked->getAttributes(), true);

            return $auction;
        }, attempts: 3);
    }

    private function check(Auction $auction, User $buyer, int $window): void
    {
        if ($auction->status !== AuctionStatus::Live || $auction->ends_at === null || ! now()->lessThan($auction->ends_at)) {
            throw ValidationException::withMessages(['reason' => __('Bidding on this auction is closed.')]);
        }

        $closing = (int) config('auction.trust.retract_closing_minutes');

        if (now()->addMinutes($closing)->greaterThanOrEqualTo($auction->ends_at)) {
            throw ValidationException::withMessages(['reason' => __('Bids cannot be taken back in the last :minutes minutes of an auction.', ['minutes' => $closing])]);
        }

        $latest = Bid::query()->where('auction_id', $auction->id)->where('user_id', $buyer->id)->standing()->max('created_at');

        if ($latest === null) {
            throw ValidationException::withMessages(['reason' => __('You have no bids to take back on this auction.')]);
        }

        if (now()->subMinutes($window)->greaterThan($latest)) {
            throw ValidationException::withMessages(['reason' => __('Bids can only be taken back within :minutes minutes of your latest bid.', ['minutes' => $window])]);
        }
    }

    /** The retracting buyer led: the best remaining bidder who can cover their bid leads. */
    private function handOver(Auction $auction, User $buyer): void
    {
        // Each remaining bidder's best bid: highest amount, then the earliest.
        $candidates = Bid::query()
            ->where('auction_id', $auction->id)
            ->where('user_id', '!=', $buyer->id)
            ->standing()
            ->orderByDesc('amount')
            ->orderBy('seq')
            ->get(['user_id', 'amount', 'seq'])
            ->unique('user_id')
            ->values();

        $walletIds = Wallet::query()
            ->whereIn('user_id', [$buyer->id, ...$candidates->pluck('user_id')])
            ->pluck('id', 'user_id');

        $wallets = $this->ledger->lock(...$walletIds->values()->all());

        $hold = WalletHold::query()->active()->where('auction_id', $auction->id)->where('type', HoldType::Bid)->lockForUpdate()->first();

        if ($hold !== null) {
            $this->ledger->release($hold);
        }

        $leader = null;
        $price = $auction->starting_price;

        foreach ($candidates as $bid) {
            $wallet = $wallets[$walletIds[$bid->user_id] ?? 0] ?? null;

            if ($wallet !== null && $wallet->available()->greaterThanOrEqual($bid->amount)) {
                $this->ledger->hold($wallet, $bid->amount, HoldType::Bid, $auction);
                $leader = $bid->user_id;
                $price = $bid->amount;

                break;
            }
        }

        $auction->forceFill([
            'leader_id' => $leader,
            'current_price' => $price,
            'reserve_met' => $leader !== null && $this->meetsReserve($auction->reserve_price, $price),
        ])->save();
    }

    private function meetsReserve(?Money $reserve, Money $price): bool
    {
        return $reserve === null || $price->greaterThanOrEqual($reserve);
    }
}
