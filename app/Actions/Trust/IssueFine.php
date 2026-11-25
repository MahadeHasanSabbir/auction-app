<?php

namespace App\Actions\Trust;

use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Invoices\Invoices;
use App\Services\Ledger\InsufficientFundsException;
use App\Services\Ledger\Ledger;
use App\Services\Notifications\AccountAlerts;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Platform staff fine a buyer or a company: the amount leaves the wallet
 * for the fines account, and the payer gets a numbered fine notice. Only
 * the available balance can be fined (money on hold is someone's bid or
 * payout). Fines are not taxed. Logged to the `money` activity log.
 */
final class IssueFine
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Invoices $invoices,
    ) {}

    public function handle(User $staff, Wallet $wallet, Money|string $amount, string $reason): Invoice
    {
        Gate::forUser($staff)->authorize('fine', $wallet);

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give the reason in a few words.')]);
        }

        try {
            $amount = $amount instanceof Money ? $amount : Money::parse($amount);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount.')]);
        }

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount above zero.')]);
        }

        return DB::transaction(function () use ($staff, $wallet, $amount, $reason): Invoice {
            try {
                $transaction = $this->ledger->transaction(LedgerTransactionType::Fine)
                    ->reference($wallet->company_id !== null ? $wallet->company()->firstOrFail() : $wallet->user()->withTrashed()->firstOrFail())
                    ->describe("Fine: {$reason}")
                    ->by($staff)
                    // Bonus credit goes first, so a fine never eats real
                    // money that a pending withdrawal has set aside.
                    ->spendingBonusFirst()
                    ->debit($wallet, $amount)
                    ->credit($this->ledger->system(SystemAccount::PenaltyRevenue), $amount)
                    ->post();
            } catch (InsufficientFundsException $e) {
                throw ValidationException::withMessages(['amount' => __('The wallet has :available available.', ['available' => $e->available->format()])]);
            }

            $invoice = $this->invoices->fine($transaction, $wallet, $amount, $reason, $transaction);

            activity('money')
                ->causedBy($staff)
                ->performedOn($invoice)
                ->event('fined')
                ->withProperties(['wallet_id' => $wallet->id, 'amount' => $amount->amount, 'reason' => $reason])
                ->log("fined Wallet {$wallet->id}");

            app(AccountAlerts::class)->fine($wallet, $invoice, $reason);

            return $invoice;
        });
    }
}
