<?php

namespace App\Livewire\Wallet;

use App\Actions\Wallet\CancelWithdrawal;
use App\Actions\Wallet\RedeemBonusCoupon;
use App\Actions\Wallet\StartDeposit;
use App\Enums\DepositStatus;
use App\Enums\FeeUseCase;
use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Fees\FeeResolver;
use App\Services\Payments\CheckoutUrls;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentGateways;
use App\Support\Home;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A buyer's wallet: balance, what is held, what can be bid, adding money,
 * withdrawals and the statement. Company wallets are shown in the seller panel.
 */
#[Layout('layouts.site')]
class Show extends Component
{
    use WithPagination;

    /** Set by the payment return (PaymentCallbackController) to say how a payment went. */
    #[Url(except: '')]
    public string $deposit = '';

    #[Url(except: '')]
    public string $outcome = '';

    public string $amount = '';

    public string $gateway = '';

    public string $bonusCode = '';

    public function mount(PaymentGateways $gateways): void
    {
        $user = $this->user();

        if (! $user->isBuyer()) {
            $this->redirect(Home::pathFor($user));

            return;
        }

        $this->gateway = $gateways->enabled()[0] ?? '';
    }

    public function startDeposit(StartDeposit $start): void
    {
        $this->validate([
            'amount' => ['required', 'string', 'max:20'],
            'gateway' => ['required', 'string'],
        ]);

        $amount = $this->parsedAmount();

        if ($amount === null) {
            $this->addError('amount', __('Enter an amount like 1500 or 1500.50.'));

            return;
        }

        $user = $this->user();
        $key = 'deposit:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('auction.payments.deposits_per_hour'))) {
            $this->addError('amount', __('Too many payments started. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]));

            return;
        }

        RateLimiter::hit($key, 3600);

        try {
            ['checkout' => $checkout] = $start->handle($user, $amount, $this->gateway, CheckoutUrls::forGateway($this->gateway));
        } catch (PaymentGatewayException $e) {
            report($e);
            $this->addError('gateway', __('This payment method is not available right now. Try again later or choose another one.'));

            return;
        }

        $this->redirect($checkout->redirectUrl);
    }

    public function redeemCode(RedeemBonusCoupon $redeem): void
    {
        $this->resetValidation();

        $amount = $redeem->handle($this->user(), $this->bonusCode);

        $this->reset('bonusCode');
        $this->dispatch('toast', message: __(':amount bonus credit added to your wallet.', ['amount' => $amount->format()]));
    }

    public function cancelWithdrawal(int $id, CancelWithdrawal $cancel): void
    {
        $withdrawal = WithdrawalRequest::query()->where('requested_by', $this->user()->id)->findOrFail($id);

        $cancel->handle($this->user(), $withdrawal);

        $this->dispatch('toast', message: __('Withdrawal cancelled. The money is available again.'));
    }

    /** Sent by the live account listener when the viewer's wallet changes (a bid, being outbid, a payment). */
    #[On('wallet-updated')]
    public function walletUpdated(): void
    {
        // Nothing to do: the call itself re-renders the figures.
    }

    public function render(PaymentGateways $gateways, FeeResolver $fees): View
    {
        $user = $this->user();
        $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();

        return view('livewire.wallet.show', [
            'wallet' => $wallet,
            'biddable' => $wallet->available()->basisPointsFloor((int) config('auction.bidding.biddable_share_bps')),
            'gateways' => $gateways->all(),
            'quote' => $this->quote($fees, $user),
            'notice' => $this->paymentNotice($user),
            'entries' => LedgerEntry::query()
                ->where('wallet_id', $wallet->id)
                ->with('transaction')
                ->latest('id')
                ->paginate(15),
            'withdrawals' => WithdrawalRequest::query()
                ->where('wallet_id', $wallet->id)
                ->latest('id')
                ->limit(5)
                ->get(),
            'deposits' => Deposit::query()
                ->where('wallet_id', $wallet->id)
                ->latest('id')
                ->limit(5)
                ->get(),
            'limits' => [
                'min' => Money::minor((int) config('auction.payments.deposit_min')),
                'max' => Money::minor((int) config('auction.payments.deposit_max')),
            ],
        ])->title(__('Wallet'));
    }

    /**
     * What the buyer will be credited for the amount typed so far.
     *
     * @return array{charges: Money, net: Money}|null
     */
    private function quote(FeeResolver $fees, User $user): ?array
    {
        $amount = $this->parsedAmount();

        if ($amount === null || ! $amount->isPositive() || $this->gateway === '') {
            return null;
        }

        $charges = $fees->charges(FeeUseCase::Deposit, $amount, $user->user_type_id, $this->gateway);
        $total = $charges['gateway']->amount->plus($charges['service']->amount);

        return ['charges' => $total, 'net' => $amount->minus($total)];
    }

    /**
     * The message for a payer coming back from the gateway, from the deposit's
     * own status (the return URL's hints are only used while it is pending).
     *
     * @return array{type: string, message: string}|null
     */
    private function paymentNotice(User $user): ?array
    {
        $deposit = $this->deposit === ''
            ? null
            : Deposit::query()->where('user_id', $user->id)->where('tran_id', $this->deposit)->first();

        return match (true) {
            $deposit?->status === DepositStatus::Succeeded => ['type' => 'success', 'message' => __(':amount was added to your wallet.', ['amount' => $deposit->net_amount->format()])],
            $deposit?->status === DepositStatus::Failed, $this->outcome === 'failed' => ['type' => 'error', 'message' => __('The payment did not go through. Nothing was added to your wallet.')],
            $deposit?->status === DepositStatus::Cancelled, $this->outcome === 'cancelled' => ['type' => 'info', 'message' => __('You cancelled the payment. Nothing was charged.')],
            $deposit?->status === DepositStatus::Pending => ['type' => 'info', 'message' => __('We are waiting for the payment gateway to confirm your payment. Your balance updates as soon as it does.')],
            default => null,
        };
    }

    private function parsedAmount(): ?Money
    {
        try {
            return $this->amount === '' ? null : Money::parse($this->amount);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
