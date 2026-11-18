<?php

namespace App\Actions\Wallet;

use App\Actions\Wallet\Concerns\ChangesWithdrawalStatus;
use App\Enums\LedgerComponent;
use App\Enums\LedgerTransactionType;
use App\Enums\NotificationKind;
use App\Enums\SystemAccount;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Ledger\Ledger;
use App\Services\Notifications\AccountAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Staff record that they sent an approved withdrawal (bank transfer or
 * mobile wallet), with the transfer's reference.
 *
 * Postings, capturing the request's hold: the wallet is debited the amount,
 * the gateway-charges and service-revenue accounts get the charges, and
 * payout clearing gets the net (it mirrors what left the platform's bank).
 */
final class PayWithdrawal
{
    use ChangesWithdrawalStatus;

    public function __construct(private readonly Ledger $ledger) {}

    public function handle(User $staff, WithdrawalRequest $withdrawal, string $reference): WithdrawalRequest
    {
        Gate::forUser($staff)->authorize('review', $withdrawal);

        $reference = trim($reference);

        if ($reference === '' || mb_strlen($reference) > 255) {
            throw ValidationException::withMessages(['reference' => __('Enter the reference of the transfer.')]);
        }

        return DB::transaction(function () use ($staff, $withdrawal, $reference): WithdrawalRequest {
            $locked = $this->lockFor($withdrawal, WithdrawalStatus::Paid);
            $locked->loadMissing(['gatewayFeeRule', 'serviceFeeRule']);

            $transaction = $this->ledger->transaction(LedgerTransactionType::Withdrawal)
                ->reference($locked)
                ->idempotent("withdrawal:{$locked->id}")
                ->describe("Withdrawal {$locked->id} to {$locked->destinationSummary()}, reference {$reference}")
                ->by($staff)
                ->capturing($this->activeHold($locked))
                ->debit(Wallet::query()->findOrFail($locked->wallet_id), $locked->amount)
                ->credit($this->ledger->system(SystemAccount::GatewayCharges), $locked->gateway_charge, LedgerComponent::GatewayCharge, $locked->gatewayFeeRule)
                ->credit($this->ledger->system(SystemAccount::ServiceRevenue), $locked->service_charge, LedgerComponent::ServiceCharge, $locked->serviceFeeRule)
                ->credit($this->ledger->system(SystemAccount::PayoutClearing), $locked->net_amount)
                ->post();

            $locked->forceFill([
                'status' => WithdrawalStatus::Paid,
                'payout_reference' => $reference,
                'paid_at' => now(),
                'ledger_transaction_id' => $transaction->id,
            ])->save();

            $this->logReview($staff, $locked, 'paid', $reference);

            app(AccountAlerts::class)->withdrawal($locked, NotificationKind::WithdrawalPaid, __('The payout was sent. Reference: :reference.', ['reference' => $reference]));

            return $this->syncFrom($withdrawal, $locked);
        });
    }
}
