<?php

namespace App\Actions\Wallet;

use App\Enums\DepositStatus;
use App\Enums\FeeUseCase;
use App\Enums\UserRole;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fees\FeeResolver;
use App\Services\Payments\CheckoutRequest;
use App\Services\Payments\CheckoutSession;
use App\Services\Payments\CheckoutUrls;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentGateways;
use App\Support\Money;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A buyer tops up their wallet: the charges are quoted, a pending deposit is
 * recorded and the gateway is asked where to send the payer. Nothing is
 * credited here; CompleteDeposit does that once the gateway confirms.
 *
 * The payer is charged the full amount; the gateway and service charges are
 * taken out of it and the rest is credited.
 */
final class StartDeposit
{
    public function __construct(
        private readonly FeeResolver $fees,
        private readonly PaymentGateways $gateways,
    ) {}

    /**
     * @return array{deposit: Deposit, checkout: CheckoutSession}
     *
     * @throws ValidationException when the amount or gateway is not acceptable
     * @throws PaymentGatewayException when the gateway refuses
     */
    public function handle(User $user, Money $amount, string $gateway, CheckoutUrls $urls): array
    {
        if ($user->role !== UserRole::Buyer) {
            throw new LogicException('Only buyers top up a wallet.');
        }

        if (! $this->gateways->isEnabled($gateway)) {
            throw ValidationException::withMessages(['gateway' => __('Choose one of the payment methods offered.')]);
        }

        $min = Money::minor((int) config('auction.payments.deposit_min'));
        $max = Money::minor((int) config('auction.payments.deposit_max'));

        if ($amount->lessThan($min) || $amount->greaterThan($max)) {
            throw ValidationException::withMessages([
                'amount' => __('Enter an amount between :min and :max.', ['min' => $min->format(), 'max' => $max->format()]),
            ]);
        }

        $charges = $this->fees->charges(FeeUseCase::Deposit, $amount, $user->user_type_id, $gateway);
        $net = $amount->minus($charges['gateway']->amount)->minus($charges['service']->amount);

        if (! $net->isPositive()) {
            throw ValidationException::withMessages(['amount' => __('The amount does not cover the charges. Enter a larger amount.')]);
        }

        $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();

        $deposit = new Deposit;
        $deposit->forceFill([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'gateway' => $gateway,
            'tran_id' => 'DEP'.Str::upper((string) Str::ulid()),
            'amount' => $amount,
            'gateway_charge' => $charges['gateway']->amount,
            'service_charge' => $charges['service']->amount,
            'net_amount' => $net,
            'gateway_fee_rule_id' => $charges['gateway']->rule?->id,
            'service_fee_rule_id' => $charges['service']->rule?->id,
            'currency' => $amount->currency,
            'status' => DepositStatus::Pending,
        ])->save();

        // The gateway is called outside any database transaction.
        try {
            $checkout = $this->gateways->get($gateway)->checkout(new CheckoutRequest(
                tranId: $deposit->tran_id,
                amount: $amount,
                description: __('Wallet top-up'),
                customerName: $user->name,
                customerEmail: $user->email,
                urls: $urls,
            ));
        } catch (PaymentGatewayException $e) {
            $deposit->forceFill([
                'status' => DepositStatus::Failed,
                'failure_reason' => Str::limit($e->getMessage(), 250),
            ])->save();

            throw $e;
        }

        if ($checkout->gatewayReference !== null) {
            $deposit->forceFill(['gateway_reference' => $checkout->gatewayReference])->save();
        }

        return ['deposit' => $deposit, 'checkout' => $checkout];
    }
}
