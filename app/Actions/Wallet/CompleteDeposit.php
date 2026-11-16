<?php

namespace App\Actions\Wallet;

use App\Enums\DepositStatus;
use App\Enums\LedgerComponent;
use App\Enums\LedgerTransactionType;
use App\Enums\PaymentOutcome;
use App\Enums\SystemAccount;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Services\Ledger\Ledger;
use App\Services\Payments\InvalidCallbackException;
use App\Services\Payments\PaymentResult;
use Illuminate\Support\Facades\DB;

/**
 * Records what the gateway confirmed about a deposit. A success credits the
 * wallet once, however often the gateway calls back; the payer's browser
 * redirect and the server notification can arrive in any order.
 *
 * A success that arrives after the deposit was marked failed or cancelled is
 * still credited: the gateway has the money. A paid amount that differs from
 * the deposit is refused and the deposit stays pending for staff.
 *
 * Postings: the buyer gets the net amount, the gateway-charges and
 * service-revenue accounts get the charges, and gateway clearing is debited
 * the full amount (it mirrors what the gateway owes the platform).
 */
final class CompleteDeposit
{
    public function __construct(private readonly Ledger $ledger) {}

    /** @throws InvalidCallbackException when the result does not match a deposit */
    public function handle(PaymentResult $result): Deposit
    {
        return DB::transaction(function () use ($result): Deposit {
            $deposit = Deposit::query()->where('tran_id', $result->tranId)->lockForUpdate()->first()
                ?? throw new InvalidCallbackException("There is no deposit [{$result->tranId}].");

            if ($deposit->gateway !== $result->gateway) {
                throw new InvalidCallbackException("Deposit [{$deposit->tran_id}] was not made through {$result->gateway}.");
            }

            if ($deposit->status === DepositStatus::Succeeded) {
                return $deposit;
            }

            if ($result->outcome !== PaymentOutcome::Succeeded) {
                if ($deposit->status === DepositStatus::Pending) {
                    $deposit->forceFill([
                        'status' => $result->outcome->depositStatus(),
                        'gateway_reference' => $result->gatewayReference ?? $deposit->gateway_reference,
                        'gateway_payload' => $result->payload,
                    ])->save();
                }

                return $deposit;
            }

            if (! $result->amount->equals($deposit->amount)) {
                throw new InvalidCallbackException(
                    "Deposit [{$deposit->tran_id}] is for {$deposit->amount->toDecimal()} {$deposit->currency}; "
                    ."the gateway reports {$result->amount->toDecimal()} {$result->amount->currency}."
                );
            }

            $deposit->loadMissing(['gatewayFeeRule', 'serviceFeeRule']);

            $transaction = $this->ledger->transaction(LedgerTransactionType::Deposit)
                ->reference($deposit)
                ->idempotent("deposit:{$deposit->id}")
                ->describe("Deposit {$deposit->tran_id} through {$deposit->gateway}")
                ->credit(Wallet::query()->findOrFail($deposit->wallet_id), $deposit->net_amount)
                ->credit($this->ledger->system(SystemAccount::GatewayCharges), $deposit->gateway_charge, LedgerComponent::GatewayCharge, $deposit->gatewayFeeRule)
                ->credit($this->ledger->system(SystemAccount::ServiceRevenue), $deposit->service_charge, LedgerComponent::ServiceCharge, $deposit->serviceFeeRule)
                ->debit($this->ledger->system(SystemAccount::GatewayClearing), $deposit->amount)
                ->post();

            $deposit->forceFill([
                'status' => DepositStatus::Succeeded,
                'gateway_reference' => $result->gatewayReference ?? $deposit->gateway_reference,
                'gateway_payload' => $result->payload,
                'failure_reason' => null,
                'ledger_transaction_id' => $transaction->id,
                'completed_at' => now(),
            ])->save();

            return $deposit;
        });
    }
}
