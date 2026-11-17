<?php

namespace App\Livewire\Wallet;

use App\Actions\Wallet\RequestWithdrawal;
use App\Enums\PayoutMethod;
use App\Http\Middleware\EnsureOtpConfirmed;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Home;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A buyer asks for a payout. The route sits behind otp.confirmed; the
 * submit checks again, because Livewire's later requests do not pass
 * through the route's middleware.
 */
#[Layout('layouts.site')]
class Withdraw extends Component
{
    public string $amount = '';

    public string $method = 'bkash';

    public string $account_name = '';

    public string $account_number = '';

    public string $bank_name = '';

    public string $branch = '';

    public string $routing_number = '';

    public function mount(): void
    {
        $user = $this->user();

        if (! $user->isBuyer()) {
            $this->redirect(Home::pathFor($user));

            return;
        }

        $this->account_name = $user->name;
    }

    public function submit(RequestWithdrawal $request): void
    {
        if (! EnsureOtpConfirmed::confirmedIn(session()->driver())) {
            session()->put('url.intended', route('wallet.withdraw'));
            $this->redirectRoute('otp.confirm');

            return;
        }

        $method = PayoutMethod::tryFrom($this->method);
        $amount = $this->parsedAmount();

        if ($method === null) {
            $this->addError('method', __('Choose where to send the money.'));

            return;
        }

        if ($amount === null) {
            $this->addError('amount', __('Enter an amount like 1500 or 1500.50.'));

            return;
        }

        $request->handle($this->user(), $this->wallet(), $amount, $method, [
            'account_name' => trim($this->account_name),
            'account_number' => preg_replace('/[\s-]+/', '', $this->account_number),
            'bank_name' => $method === PayoutMethod::Bank ? trim($this->bank_name) : null,
            'branch' => $method === PayoutMethod::Bank ? trim($this->branch) : null,
            'routing_number' => $method === PayoutMethod::Bank ? (trim($this->routing_number) ?: null) : null,
        ]);

        session()->flash('status', __('Your withdrawal of :amount is on its way to review. The money is on hold until it is paid.', ['amount' => $amount->format()]));
        $this->redirectRoute('wallet');
    }

    public function render(RequestWithdrawal $request): View
    {
        $wallet = $this->wallet();
        $amount = $this->parsedAmount();
        $method = PayoutMethod::tryFrom($this->method);

        return view('livewire.wallet.withdraw', [
            'wallet' => $wallet,
            'methods' => PayoutMethod::cases(),
            'isBank' => $method === PayoutMethod::Bank,
            'quote' => $amount !== null && $amount->isPositive() && $method !== null ? $request->quote($wallet, $amount, $method) : null,
            'min' => Money::minor((int) config('auction.payments.withdrawal_min')),
        ])->title(__('Withdraw'));
    }

    private function parsedAmount(): ?Money
    {
        try {
            return $this->amount === '' ? null : Money::parse($this->amount);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function wallet(): Wallet
    {
        return Wallet::query()->where('user_id', $this->user()->id)->firstOrFail();
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
