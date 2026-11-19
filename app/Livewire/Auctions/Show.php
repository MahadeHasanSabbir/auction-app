<?php

namespace App\Livewire\Auctions;

use App\Actions\Bidding\BuyNow;
use App\Actions\Bidding\PlaceBid;
use App\Actions\Bidding\RetractBids;
use App\Actions\Bidding\ToggleWatchlist;
use App\Enums\AuctionStatus;
use App\Enums\HoldType;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Services\Realtime\AuctionSnapshot;
use App\Services\Realtime\ViewerCount;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One auction: pictures, description, price, time left, the bid history,
 * and for a signed-in buyer the bid form (by hand or automatic up to a
 * private maximum), buy now and the watchlist button. Every action calls
 * the same action class as any other caller.
 *
 * It is also the live bidding room: the page renders an AuctionSnapshot and
 * the `auctionRoom` Alpine component (resources/js/live.js) keeps it current
 * from the auction's public channel, without a request per bid. Only what
 * concerns the viewer alone (their maximum, how much they can bid) and a
 * change of status come back here as a refresh.
 */
#[Layout('layouts.site')]
class Show extends Component
{
    #[Locked]
    public int $auctionId;

    public string $amount = '';

    public bool $auto = false;

    public string $retractReason = '';

    /** Minted with the form, so a double submit places one bid. */
    #[Locked]
    public string $formKey = '';

    public function mount(Auction $auction): void
    {
        // Drafts, rejected and cancelled auctions do not exist for the public.
        abort_unless($auction->status->isPublic(), 404);

        $this->auctionId = $auction->id;
        $this->formKey = (string) Str::uuid();
    }

    public function placeBid(PlaceBid $placeBid): void
    {
        // A refusal from an earlier attempt no longer applies.
        $this->resetErrorBag();

        $user = $this->buyer();

        if ($user === null) {
            return;
        }

        try {
            $result = $placeBid->handle($user, $this->auction(), $this->amount, $this->auto, $this->formKey);
        } catch (ValidationException $e) {
            $this->addError('amount', (string) collect($e->errors())->flatten()->first());

            return;
        } catch (AuthorizationException) {
            $this->addError('amount', __('This account cannot bid.'));

            return;
        }

        $this->reset('amount');
        $this->formKey = (string) Str::uuid();

        $message = $result->message().($result->extended ? ' '.__('The auction was extended.') : '');
        $this->dispatch('toast', message: $message, type: $result->leading ? 'success' : 'error');
    }

    public function buyNow(BuyNow $buyNow): void
    {
        // A refusal from an earlier attempt no longer applies.
        $this->resetErrorBag();

        $user = $this->buyer();

        if ($user === null) {
            return;
        }

        try {
            $order = $buyNow->handle($user, $this->auction(), $this->formKey);
        } catch (ValidationException $e) {
            $this->addError('buy_now', (string) collect($e->errors())->flatten()->first());

            return;
        } catch (AuthorizationException) {
            $this->addError('buy_now', __('This account cannot buy.'));

            return;
        }

        $this->formKey = (string) Str::uuid();
        $this->dispatch('toast', message: __('It is yours. Order :number is paid into escrow.', ['number' => $order->number]));
    }

    /** The buyer takes back their bids (a strike; see RetractBids). */
    public function retract(RetractBids $retract): void
    {
        $this->resetErrorBag();

        $user = $this->buyer();

        if ($user === null) {
            return;
        }

        if (! array_key_exists($this->retractReason, self::retractReasons())) {
            $this->addError('retract', __('Choose a reason.'));

            return;
        }

        try {
            $retract->handle($user, $this->auction(), self::retractReasons()[$this->retractReason]);
        } catch (ValidationException $e) {
            $this->addError('retract', (string) collect($e->errors())->flatten()->first());

            return;
        } catch (AuthorizationException) {
            $this->addError('retract', __('This account cannot bid.'));

            return;
        }

        $this->reset('retractReason');
        $this->dispatch('toast', message: __('Your bids on this auction were taken back. A strike was added to your account.'));
    }

    /** @return array<string, string> */
    public static function retractReasons(): array
    {
        return [
            'typo' => __('I entered the wrong amount'),
            'changed' => __('The description changed after I bid'),
            'unreachable' => __('I cannot reach the seller'),
        ];
    }

    public function toggleWatch(ToggleWatchlist $toggle): void
    {
        $user = $this->buyer();

        if ($user === null) {
            return;
        }

        try {
            $watching = $toggle->handle($user, $this->auction());
        } catch (ValidationException|AuthorizationException) {
            return;
        }

        $this->dispatch('toast', message: $watching ? __('Added to your watchlist.') : __('Removed from your watchlist.'));
    }

    /** The signed-in user, or a redirect to sign in. */
    private function buyer(): ?User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            $this->redirectRoute('login');

            return null;
        }

        return $user;
    }

    private function auction(): Auction
    {
        return Auction::query()->findOrFail($this->auctionId);
    }

    /** Sent by the live account listener when the viewer's wallet changes (a bid, being outbid, a payment). */
    #[On('wallet-updated')]
    public function walletUpdated(): void
    {
        // Nothing to do: the call itself re-renders the figures.
    }

    public function render(): View
    {
        $auction = Auction::query()
            ->with([
                'company:id,name,slug,about,rating_sum,rating_count,verified_at,kyc_status',
                'product' => fn ($query) => $query->withTrashed(),
                'product.images',
                'product.category:id,name,slug,parent_id',
                'product.category.parent:id,name,slug',
                'winner:id,name',
            ])
            ->findOrFail($this->auctionId);

        abort_unless($auction->status->isPublic(), 404);

        $user = Auth::user();
        $open = in_array($auction->status, [AuctionStatus::Scheduled, AuctionStatus::Live], true);
        $me = $user === null ? null : AuctionSnapshot::ref($auction->id, $user->id);

        // Signed-in pages count the presence channel themselves; guests get the server's count.
        $snapshot = AuctionSnapshot::of($auction, viewers: $open && $user === null ? app(ViewerCount::class)->of($auction->id) : null);

        // The viewer's own bids read "You"; their masked name is not needed on their own page.
        $snapshot['bids'] = array_map(
            fn (array $bid): array => $bid['ref'] === $me ? ['bidder' => __('You')] + $bid : $bid,
            $snapshot['bids'],
        );

        return view('livewire.auctions.show', [
            'auction' => $auction,
            'snapshot' => $snapshot,
            'me' => $me,
            'open' => $open,
            'viewerId' => $user?->id,
            'canBid' => $user instanceof User && $user->can('bid', $auction),
            'viewer' => $user instanceof User ? $this->viewerState($user, $auction) : null,
            'retractReasons' => self::retractReasons(),
        ])->title($auction->title);
    }

    /**
     * Where the signed-in viewer stands: their maximum (private to them),
     * how much they can bid, and whether they watch the auction.
     *
     * @return array{isBuyer: bool, verified: bool, identityCleared: bool, watching: bool, hasBid: bool, canRetract: bool, autoMax: ?Money, biddable: ?Money, available: ?Money}
     */
    private function viewerState(User $user, Auction $auction): array
    {
        $wallet = $user->isBuyer() ? Wallet::query()->where('user_id', $user->id)->first() : null;
        $autoBid = AutoBid::query()->where('auction_id', $auction->id)->where('user_id', $user->id)->where('is_active', true)->first();
        $ownHold = $wallet === null ? null : WalletHold::query()->active()
            ->where('auction_id', $auction->id)
            ->where('type', HoldType::Bid)
            ->where('wallet_id', $wallet->id)
            ->first()?->amount;
        $base = $ownHold === null ? $wallet?->available() : $wallet->available()->plus($ownHold);
        $latestBid = $auction->bids()->standing()->where('user_id', $user->id)->max('created_at');

        return [
            'isBuyer' => $user->isBuyer(),
            'verified' => $user->hasVerifiedEmail(),
            'identityCleared' => $user->kyc_status->isCleared(),
            'watching' => $user->watchedAuctions()->whereKey($auction->id)->exists(),
            'hasBid' => $latestBid !== null,
            'canRetract' => $this->canRetract($auction, $latestBid),
            'autoMax' => $autoBid?->max_amount,
            'biddable' => $base?->basisPointsFloor((int) config('auction.bidding.biddable_share_bps')),
            'available' => $wallet?->available(),
        ];
    }

    /** Whether the window to take back bids is open (RetractBids decides for real). */
    private function canRetract(Auction $auction, mixed $latestBid): bool
    {
        $window = (int) config('auction.trust.retract_window_minutes');

        return $latestBid !== null && $window > 0
            && $auction->status === AuctionStatus::Live && $auction->ends_at !== null
            && now()->addMinutes((int) config('auction.trust.retract_closing_minutes'))->lessThan($auction->ends_at)
            && now()->subMinutes($window)->lessThanOrEqualTo($latestBid);
    }
}
