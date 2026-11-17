<?php

namespace App\Actions\Bidding;

use App\Actions\Bidding\Concerns\BidsOnAuctions;
use App\Enums\AuctionStatus;
use App\Enums\HoldType;
use App\Events\Account\Outbid;
use App\Events\Auctions\BidPlaced;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\Bid;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Services\Bidding\BidState;
use App\Services\Bidding\ProxyResolver;
use App\Services\Bidding\Resolution;
use App\Services\Ledger\Ledger;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A buyer bids on a live auction, by hand (the amount is the bid) or
 * automatically (the amount is a private maximum the system bids up to).
 *
 * One short transaction, retried on deadlock: the auction row is locked
 * first, then the bidder's and the leader's wallets (Ledger::lock order),
 * then the auction's bid hold. The end time is checked inside it, so a bid
 * can never land after the close. The leader always holds their whole
 * maximum, so an automatic bid can never fail for lack of money.
 */
final class PlaceBid
{
    use BidsOnAuctions;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly ProxyResolver $resolver,
    ) {}

    public function handle(User $bidder, Auction $auction, Money|string|null $amount, bool $auto = false, ?string $key = null): BidResult
    {
        Gate::forUser($bidder)->authorize('bid', $auction);

        $max = $this->amount($amount);

        if (($stored = $this->remembered($bidder, $key, 'bid')) !== null) {
            return BidResult::fromArray($stored);
        }

        $this->throttle($bidder);

        try {
            return DB::transaction(fn () => $this->place($bidder, $auction, $max, $auto, $key), attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            // The same form sent twice: answer with what the first one did.
            if (($stored = $this->remembered($bidder, $key, 'bid')) !== null) {
                return BidResult::fromArray($stored);
            }

            throw $e;
        }
    }

    private function place(User $bidder, Auction $auction, Money $max, bool $auto, ?string $key): BidResult
    {
        $claim = $this->claim($bidder, $key, 'bid');

        $locked = Auction::query()->lockForUpdate()->findOrFail($auction->id);

        if ($locked->status !== AuctionStatus::Live || $locked->ends_at === null || ! now()->lessThan($locked->ends_at)) {
            throw ValidationException::withMessages(['amount' => __('Bidding on this auction is closed.')]);
        }

        $leaderAutoBid = $locked->leader_id === null ? null : AutoBid::query()
            ->where('auction_id', $locked->id)
            ->where('user_id', $locked->leader_id)
            ->lockForUpdate()
            ->first();

        $state = BidState::of($locked, $leaderAutoBid);
        $this->checkAmount($state, $bidder, $max, $auto);

        // Wallets in the ledger's order, then the auction's bid hold.
        $wallet = $this->walletOf($bidder);
        $leaderWallet = $locked->leader_id !== null && $locked->leader_id !== $bidder->id
            ? $this->walletOf(User::query()->findOrFail($locked->leader_id))
            : null;
        $wallets = $this->ledger->lock(...array_filter([$wallet, $leaderWallet]));
        $wallet = $wallets[$wallet->id];

        $hold = WalletHold::query()->active()->where('auction_id', $locked->id)->where('type', HoldType::Bid)->lockForUpdate()->first();

        $this->checkFunds($wallet->available(), $hold !== null && $hold->wallet_id === $wallet->id ? $hold->amount : null, $max);

        $resolution = $this->resolver->resolve($state, $bidder->id, $max, $auto);
        $previousLeader = $locked->leader_id;

        $this->writeBids($locked, $resolution);
        $this->moveHold($locked, $resolution, $hold, $wallet);
        $this->updateAutoBids($locked, $resolution, $bidder, $max, $auto);
        $extended = $this->updateAuction($locked, $resolution);

        // Sent after commit. A leader raising their own hidden maximum changes nothing anyone sees.
        if ($resolution->bids !== []) {
            BidPlaced::dispatch($locked);
        }

        if ($previousLeader !== null && $previousLeader !== $resolution->leaderId) {
            Outbid::dispatch($previousLeader, $locked);
        }

        $result = new BidResult(
            leading: $resolution->leaderId === $bidder->id,
            price: $resolution->price,
            nextMinimum: $locked->minimumNextBid(),
            extended: $extended,
        );

        $claim?->forceFill(['response' => $result->toArray()])->save();

        return $result;
    }

    private function checkAmount(BidState $state, User $bidder, Money $max, bool $auto): void
    {
        if ($state->leaderId === $bidder->id) {
            if (! $auto) {
                throw ValidationException::withMessages(['amount' => __('You are already the highest bidder. To go higher later, set a maximum with an automatic bid.')]);
            }

            if (! $max->greaterThan($state->leaderMax())) {
                throw ValidationException::withMessages(['amount' => __('Your new maximum must be higher than :amount.', ['amount' => $state->leaderMax()->format()])]);
            }

            return;
        }

        if ($max->lessThan($state->minimumNext())) {
            throw ValidationException::withMessages(['amount' => __('Bid at least :amount.', ['amount' => $state->minimumNext()->format()])]);
        }
    }

    /** A maximum may use only the marketplace's share of the available balance (plus what this auction already holds). */
    private function checkFunds(Money $available, ?Money $ownHold, Money $max): void
    {
        $base = $ownHold === null ? $available : $available->plus($ownHold);
        $biddable = $base->basisPointsFloor((int) config('auction.bidding.biddable_share_bps'));

        if ($max->greaterThan($biddable)) {
            throw ValidationException::withMessages(['amount' => __('You can bid up to :amount with your balance. Add money to your wallet to bid more.', ['amount' => $biddable->format()])]);
        }
    }

    private function writeBids(Auction $auction, Resolution $resolution): void
    {
        $seq = $auction->bid_count;

        foreach ($resolution->bids as $row) {
            $bid = new Bid;
            $bid->forceFill([
                'auction_id' => $auction->id,
                'user_id' => $row->userId,
                'amount' => $row->amount,
                'type' => $row->type,
                'seq' => ++$seq,
                'created_at' => now(),
            ])->save();
        }
    }

    /** The leader holds their whole maximum: a new leader or a raised maximum moves the hold. */
    private function moveHold(Auction $auction, Resolution $resolution, ?WalletHold $hold, Wallet $bidderWallet): void
    {
        // The other leader kept the lead: their hold already covers their maximum.
        if ($resolution->leaderId !== $bidderWallet->user_id) {
            return;
        }

        if ($hold !== null && $hold->wallet_id === $bidderWallet->id && $hold->amount->equals($resolution->leaderMax)) {
            return;
        }

        if ($hold !== null) {
            $this->ledger->release($hold);
        }

        $this->ledger->hold($bidderWallet, $resolution->leaderMax, HoldType::Bid, $auction);
    }

    private function updateAutoBids(Auction $auction, Resolution $resolution, User $bidder, Money $max, bool $auto): void
    {
        if ($auto) {
            $row = AutoBid::query()->firstOrNew(['auction_id' => $auction->id, 'user_id' => $bidder->id]);
            $row->forceFill(['max_amount' => $max, 'is_active' => $resolution->leaderId === $bidder->id])->save();
        } else {
            // A bid by hand replaces any earlier maximum of the same bidder.
            AutoBid::query()->where('auction_id', $auction->id)->where('user_id', $bidder->id)->update(['is_active' => false]);
        }

        // A leader who lost the lead has been outbid past their maximum.
        if ($auction->leader_id !== null && $auction->leader_id !== $resolution->leaderId) {
            AutoBid::query()->where('auction_id', $auction->id)->where('user_id', $auction->leader_id)->update(['is_active' => false]);
        }
    }

    /** Writes the cached state; a bid inside the anti-sniping window pushes the end back. */
    private function updateAuction(Auction $auction, Resolution $resolution): bool
    {
        $auction->forceFill([
            'current_price' => $resolution->price,
            'bid_count' => $auction->bid_count + count($resolution->bids),
            'leader_id' => $resolution->leaderId,
            'reserve_met' => $resolution->reserveMet,
        ]);

        $extended = false;
        $window = $auction->anti_snipe_window_seconds;
        $extend = $auction->anti_snipe_extend_seconds;
        $end = $auction->ends_at;

        if ($resolution->bids !== [] && $window && $extend && $end !== null
            && $auction->extension_count < (int) $auction->anti_snipe_max_extensions
            && now()->diffInSeconds($end) <= $window) {
            $newEnd = now()->addSeconds($extend);

            if ($newEnd->greaterThan($end)) {
                $auction->forceFill(['ends_at' => $newEnd, 'extension_count' => $auction->extension_count + 1]);
                $extended = true;
            }
        }

        $auction->save();

        return $extended;
    }
}
