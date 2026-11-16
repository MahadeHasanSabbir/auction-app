<?php

namespace Database\Seeders\Support;

use App\Enums\DepositStatus;
use App\Enums\HoldStatus;
use App\Enums\HoldType;
use App\Enums\LedgerComponent;
use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Models\Auction;
use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * Posts the money movements of the demo data as real double-entry rows, so a
 * seeded database balances: every transaction sums to zero and every wallet's
 * cached balance and held amount match its entries and holds.
 *
 * Seed-only. It skips the locking, fee resolution and idempotency of the real
 * Ledger service (Phase 3), which replaces it.
 */
final class DemoLedger
{
    /** @var array<string, Wallet> */
    private array $system = [];

    /** Money arrives from the (fake) gateway into a buyer's wallet. */
    public function deposit(Wallet $wallet, Money $amount, int $userId): Deposit
    {
        $deposit = Deposit::query()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $userId,
            'gateway' => 'fake',
            'tran_id' => 'DEMO-'.Str::upper(Str::random(16)),
            'amount' => $amount,
            'net_amount' => $amount,
            'currency' => $amount->currency,
            'status' => DepositStatus::Succeeded,
            'completed_at' => now(),
        ]);

        $transaction = $this->post(LedgerTransactionType::Deposit, $deposit, 'Demo deposit', [
            [$wallet, $amount, LedgerComponent::Principal],
            [$this->account(SystemAccount::GatewayClearing), $amount->negated(), LedgerComponent::Principal],
        ]);

        $deposit->update(['ledger_transaction_id' => $transaction->id]);

        return $deposit;
    }

    /** Sets money aside in the leader's wallet for a live auction. */
    public function holdForBid(Wallet $wallet, Auction $auction, Money $amount): void
    {
        if ($wallet->available()->lessThan($amount)) {
            throw new LogicException("Wallet {$wallet->id} cannot cover a hold of {$amount->toDecimal()}.");
        }

        WalletHold::query()->create([
            'wallet_id' => $wallet->id,
            'type' => HoldType::Bid,
            'status' => HoldStatus::Active,
            'amount' => $amount,
            'auction_id' => $auction->id,
        ]);

        $wallet->forceFill(['held' => $wallet->held->plus($amount)])->save();
    }

    /** The winner pays: the price moves from the buyer's wallet into escrow. */
    public function capture(Order $order, Wallet $buyerWallet): void
    {
        $this->post(LedgerTransactionType::SaleCapture, $order, "Order {$order->number} paid into escrow", [
            [$buyerWallet, $order->amount->negated(), LedgerComponent::Principal],
            [$this->account(SystemAccount::Escrow), $order->amount, LedgerComponent::Principal],
        ]);
    }

    /** Delivery confirmed: escrow pays the company, less the commission. */
    public function release(Order $order, Wallet $companyWallet, ?int $agreementId): void
    {
        $entries = [
            [$this->account(SystemAccount::Escrow), $order->amount->negated(), LedgerComponent::Principal],
            [$companyWallet, $order->seller_payout, LedgerComponent::Principal],
        ];

        if ($order->commission->isPositive()) {
            $entries[] = [$this->account(SystemAccount::CommissionRevenue), $order->commission, LedgerComponent::Commission, $agreementId];
        }

        $this->post(LedgerTransactionType::EscrowRelease, $order, "Order {$order->number} released to the seller", $entries);
    }

    /**
     * @param  list<array{0: Wallet, 1: Money, 2: LedgerComponent, 3?: int|null}>  $entries
     */
    private function post(LedgerTransactionType $type, Model $reference, string $description, array $entries): LedgerTransaction
    {
        $sum = array_sum(array_map(fn (array $entry) => $entry[1]->amount, $entries));

        if ($sum !== 0) {
            throw new LogicException("Ledger entries must sum to zero, got {$sum}.");
        }

        $transaction = LedgerTransaction::query()->create([
            'type' => $type,
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'description' => $description,
        ]);

        foreach ($entries as $entry) {
            [$wallet, $amount, $component] = $entry;

            $wallet->forceFill(['balance' => $wallet->balance->plus($amount)])->save();

            LedgerEntry::query()->create([
                'ledger_transaction_id' => $transaction->id,
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'component' => $component,
                'company_agreement_id' => $entry[3] ?? null,
                'balance_after' => $wallet->balance,
            ]);
        }

        return $transaction;
    }

    private function account(SystemAccount $account): Wallet
    {
        return $this->system[$account->value] ??= Wallet::forSystem($account);
    }
}
