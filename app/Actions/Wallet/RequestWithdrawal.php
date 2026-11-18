<?php

namespace App\Actions\Wallet;

use App\Enums\FeeUseCase;
use App\Enums\HoldType;
use App\Enums\PayoutMethod;
use App\Enums\WithdrawalStatus;
use App\Models\Company;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Fees\Fee;
use App\Services\Fees\FeeResolver;
use App\Services\Ledger\InsufficientFundsException;
use App\Services\Ledger\Ledger;
use App\Support\Kyc;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A buyer (or a company owner, for the company wallet) asks to be paid out.
 * The amount is put on hold at once, so it cannot be bid or withdrawn twice;
 * staff then approve and pay it by hand (PayWithdrawal) or reject it.
 *
 * The charges are quoted now and taken out of the amount: the wallet loses
 * the amount, the payee receives the net.
 */
final class RequestWithdrawal
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly FeeResolver $fees,
    ) {}

    /**
     * @param  array<string, mixed>  $destination  account_name, account_number, and for a bank bank_name, branch, routing_number
     *
     * @throws ValidationException when the amount or destination is not acceptable
     */
    public function handle(User $user, Wallet $wallet, Money $amount, PayoutMethod $method, array $destination): WithdrawalRequest
    {
        Gate::forUser($user)->authorize('withdraw', $wallet);

        if (($holder = Kyc::subjectOf($wallet)) !== null) {
            Kyc::ensureCleared($holder, 'amount');
        }

        /** @var array<string, string|null> $destination */
        $destination = Validator::make($destination, $method->destinationRules())->validate();

        $quote = $this->quote($wallet, $amount, $method);

        if ($amount->lessThan($quote['min'])) {
            throw ValidationException::withMessages(['amount' => __('The smallest withdrawal is :min.', ['min' => $quote['min']->format()])]);
        }

        if (! $quote['net']->isPositive()) {
            throw ValidationException::withMessages(['amount' => __('The amount does not cover the charges. Enter a larger amount.')]);
        }

        return DB::transaction(function () use ($user, $wallet, $amount, $method, $destination, $quote): WithdrawalRequest {
            $withdrawal = new WithdrawalRequest;
            $withdrawal->forceFill([
                'wallet_id' => $wallet->id,
                'requested_by' => $user->id,
                'amount' => $amount,
                'gateway_charge' => $quote['gateway']->amount,
                'service_charge' => $quote['service']->amount,
                'net_amount' => $quote['net'],
                'gateway_fee_rule_id' => $quote['gateway']->rule?->id,
                'service_fee_rule_id' => $quote['service']->rule?->id,
                'currency' => $amount->currency,
                'method' => $method,
                'destination' => array_filter($destination, fn (?string $value) => $value !== null && $value !== ''),
                'status' => WithdrawalStatus::Pending,
            ])->save();

            try {
                $this->ledger->hold($wallet, $amount, HoldType::Withdrawal, withdrawal: $withdrawal);
            } catch (InsufficientFundsException $e) {
                // Rolls the request back with the transaction.
                throw ValidationException::withMessages([
                    'amount' => __('You can withdraw up to :amount.', ['amount' => $e->available->format()]),
                ]);
            }

            return $withdrawal;
        });
    }

    /**
     * What a withdrawal of $amount would cost, for the form to show before
     * the request is made.
     *
     * @return array{gateway: Fee, service: Fee, net: Money, min: Money}
     */
    public function quote(Wallet $wallet, Money $amount, PayoutMethod $method): array
    {
        // Fees can differ by the owner's user type: the company's or the buyer's.
        $userTypeId = match (true) {
            $wallet->company_id !== null => Company::query()->whereKey($wallet->company_id)->value('user_type_id'),
            $wallet->user_id !== null => User::query()->whereKey($wallet->user_id)->value('user_type_id'),
            default => null,
        };

        $charges = $this->fees->charges(FeeUseCase::Withdrawal, $amount, $userTypeId, $method->value);

        return [
            'gateway' => $charges['gateway'],
            'service' => $charges['service'],
            'net' => $amount->minus($charges['gateway']->amount)->minus($charges['service']->amount),
            'min' => Money::minor((int) config('auction.payments.withdrawal_min'), $amount->currency),
        ];
    }
}
