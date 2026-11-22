<?php

namespace App\Services\Ledger;

use App\Enums\HoldStatus;
use App\Enums\HoldType;
use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Enums\WalletType;
use App\Models\Auction;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Models\WithdrawalRequest;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only code that moves money or sets it aside.
 *
 * Every movement is a balanced double-entry transaction: its entries sum to
 * zero. wallets.balance and wallets.held are caches of the entries and the
 * active holds and are written here, in the same database transaction as the
 * rows they summarise, with the wallets locked.
 *
 * Lock order, shared by every money path so that two of them can never wait
 * on each other: first the domain row (auction, order, deposit, withdrawal
 * request), then member wallets (buyers, companies) by ascending id, then
 * platform accounts by ascending id, then holds. Use lock() once with every
 * member wallet a transaction will touch before posting.
 *
 * A member wallet is never allowed to spend more than it has available
 * (balance minus held). Platform accounts may run negative: gateway clearing
 * mirrors money that sits at the gateway.
 */
final class Ledger
{
    /** @var array<string, Wallet> */
    private array $system = [];

    public function transaction(LedgerTransactionType $type): PendingTransaction
    {
        return new PendingTransaction($this, $type);
    }

    /** A platform account: escrow, commission revenue, gateway clearing, … */
    public function system(SystemAccount $account): Wallet
    {
        return $this->system[$account->value] ??= Wallet::forSystem($account);
    }

    /**
     * Locks the wallets FOR UPDATE in the shared order and returns fresh
     * copies keyed by id. Must run inside a database transaction.
     *
     * @return array<int, Wallet>
     */
    public function lock(Wallet|int ...$wallets): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Wallets can only be locked inside a database transaction.');
        }

        $types = [];
        $unknown = [];

        foreach ($wallets as $wallet) {
            if ($wallet instanceof Wallet) {
                $types[$wallet->id] = $wallet->type;
            } else {
                $unknown[] = $wallet;
            }
        }

        // The type of a wallet never changes, so a plain read is enough to
        // decide which group it is locked in.
        if ($unknown !== []) {
            foreach (Wallet::query()->whereKey($unknown)->get(['id', 'type']) as $wallet) {
                $types[$wallet->id] = $wallet->type;
            }
        }

        $members = array_keys(array_filter($types, fn (WalletType $type) => $type !== WalletType::System));
        $platform = array_keys(array_filter($types, fn (WalletType $type) => $type === WalletType::System));

        $locked = [];

        // Two queries, so that member wallets are always locked before
        // platform accounts whatever their ids.
        foreach ([$members, $platform] as $ids) {
            if ($ids === []) {
                continue;
            }

            sort($ids);

            foreach (Wallet::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get() as $wallet) {
                $locked[$wallet->id] = $wallet;
            }
        }

        $missing = array_diff([...array_keys($types), ...$unknown], array_keys($locked));

        if ($missing !== []) {
            throw new LogicException('Unknown wallet: '.implode(', ', array_unique($missing)).'.');
        }

        return $locked;
    }

    /**
     * Sets money aside for a leading bid or a pending withdrawal. The hold
     * counts against the wallet's available balance until it is released or
     * captured.
     */
    public function hold(Wallet $wallet, Money $amount, HoldType $type, ?Auction $auction = null, ?WithdrawalRequest $withdrawal = null): WalletHold
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A hold must be for a positive amount.');
        }

        if ($wallet->type === WalletType::System) {
            throw new LogicException('Platform accounts cannot hold money.');
        }

        if ($amount->currency !== $wallet->currency) {
            throw new InvalidArgumentException("Cannot hold {$amount->currency} in a {$wallet->currency} wallet.");
        }

        if (match ($type) {
            HoldType::Bid => $auction === null || $withdrawal !== null,
            HoldType::Withdrawal => $withdrawal === null || $auction !== null,
        }) {
            throw new InvalidArgumentException('A bid hold needs its auction and a withdrawal hold its request, nothing else.');
        }

        return DB::transaction(function () use ($wallet, $amount, $type, $auction, $withdrawal): WalletHold {
            $locked = $this->lock($wallet)[$wallet->id];

            // Bonus credit can be bid with but not withdrawn.
            $spendable = $type === HoldType::Withdrawal ? $locked->withdrawable() : $locked->available();

            if ($spendable->lessThan($amount)) {
                throw new InsufficientFundsException($locked->id, $spendable, $amount);
            }

            $hold = new WalletHold;
            $hold->forceFill([
                'wallet_id' => $locked->id,
                'type' => $type,
                'status' => HoldStatus::Active,
                'amount' => $amount,
                'auction_id' => $auction?->id,
                'withdrawal_request_id' => $withdrawal?->id,
            ])->save();

            $locked->forceFill(['held' => $locked->held->plus($amount)])->save();
            $this->sync($wallet, $locked);

            return $hold;
        });
    }

    /** Gives held money back to the wallet's available balance. */
    public function release(WalletHold $hold): void
    {
        DB::transaction(function () use ($hold): void {
            $wallet = $this->lock($hold->wallet_id)[$hold->wallet_id];
            $locked = WalletHold::query()->lockForUpdate()->findOrFail($hold->id);

            if ($locked->status !== HoldStatus::Active) {
                throw new LogicException("Hold {$hold->id} is {$locked->status->value}, not active.");
            }

            $locked->forceFill(['status' => HoldStatus::Released, 'released_at' => now()])->save();
            $wallet->forceFill(['held' => $wallet->held->minus($locked->amount)])->save();

            $this->sync($hold, $locked);
        });
    }

    /**
     * Writes a pending transaction. Use $ledger->transaction(...)->…->post().
     *
     * @throws InsufficientFundsException when a member wallet would spend more than it has available
     */
    public function post(PendingTransaction $pending): LedgerTransaction
    {
        $lines = $pending->lines;

        if (count($lines) < 2) {
            throw new LogicException('A ledger transaction needs at least two non-zero entries.');
        }

        $currency = $lines[0]['amount']->currency;
        $sum = 0;

        foreach ($lines as $line) {
            if ($line['amount']->currency !== $currency || $line['wallet']->currency !== $currency) {
                throw new LogicException('A ledger transaction moves one currency between wallets of that currency.');
            }

            $sum += $line['amount']->amount;
        }

        if ($sum !== 0) {
            throw new LogicException("Ledger entries must sum to zero, got {$sum}.");
        }

        return DB::transaction(function () use ($pending, $lines): LedgerTransaction {
            if ($pending->idempotencyKey !== null && ($existing = $this->existing($pending, false)) !== null) {
                return $existing;
            }

            // The transaction row goes in before any wallet is locked: a
            // concurrent post with the same key then waits here and finds
            // the first one, instead of deadlocking on the wallets.
            try {
                $transaction = new LedgerTransaction;
                $transaction->forceFill([
                    'type' => $pending->type,
                    'idempotency_key' => $pending->idempotencyKey,
                    'reference_type' => $pending->reference?->getMorphClass(),
                    'reference_id' => $pending->reference?->getKey(),
                    'description' => $pending->description,
                    'meta' => $pending->meta === [] ? null : $pending->meta,
                    'created_by' => $pending->createdBy,
                ])->save();
            } catch (UniqueConstraintViolationException $e) {
                if ($pending->idempotencyKey === null) {
                    throw $e;
                }

                return $this->existing($pending, true) ?? throw $e;
            }

            $wallets = $this->lock(...array_column($lines, 'wallet'));
            $holds = $this->lockCaptures($pending, $wallets);

            $released = [];

            foreach ($holds as $hold) {
                $released[$hold->wallet_id] = ($released[$hold->wallet_id] ?? 0) + $hold->amount->amount;
            }

            $this->assertCovered($lines, $wallets, $released);

            $entries = [];
            $now = now();

            foreach ($lines as $line) {
                $wallet = $wallets[$line['wallet']->id];
                $bonus = $this->bonusPart($wallet, $line['amount'], $line['bonus'], $pending->bonusFirst);
                $wallet->balance = $wallet->balance->plus($line['amount']);
                $wallet->bonus = $wallet->bonus->plus($bonus);

                $entry = new LedgerEntry;
                $entry->forceFill([
                    'ledger_transaction_id' => $transaction->id,
                    'wallet_id' => $wallet->id,
                    'amount' => $line['amount'],
                    'component' => $line['component'],
                    'fee_rule_id' => $line['fee_rule_id'],
                    'company_agreement_id' => $line['company_agreement_id'],
                    'balance_after' => $wallet->balance,
                    'bonus' => $bonus->amount,
                    'created_at' => $now,
                ])->save();

                $entries[] = $entry;
            }

            foreach ($holds as $hold) {
                $hold->forceFill(['status' => HoldStatus::Captured, 'released_at' => $now])->save();

                $wallet = $wallets[$hold->wallet_id];
                $wallet->held = $wallet->held->minus($hold->amount);
            }

            foreach ($wallets as $wallet) {
                $wallet->save();
            }

            foreach ($lines as $line) {
                $this->sync($line['wallet'], $wallets[$line['wallet']->id]);
            }

            foreach ($pending->captures as $hold) {
                $this->sync($hold, $holds[$hold->id]);
            }

            $transaction->setRelation('entries', new Collection($entries));

            return $transaction;
        });
    }

    /**
     * The bonus-credit part of an entry, with the entry's sign. A credit
     * carries the part its caller gave; a debit spends bonus first when
     * the transaction says so (purchases), otherwise only what real money
     * cannot cover. $wallet is the locked copy before this entry.
     */
    private function bonusPart(Wallet $wallet, Money $amount, ?int $given, bool $bonusFirst): Money
    {
        $currency = $amount->currency;

        if ($wallet->type === WalletType::System) {
            if ($given !== null && $given !== 0) {
                throw new LogicException('Platform accounts hold no bonus credit.');
            }

            return Money::zero($currency);
        }

        if (! $amount->isNegative()) {
            return Money::minor($given ?? 0, $currency);
        }

        $spent = -$amount->amount;
        $bonus = $wallet->bonus->amount;
        $real = $wallet->balance->amount - $bonus;

        $part = $bonusFirst ? min($bonus, $spent) : min($bonus, max(0, $spent - $real));

        return Money::minor(-$part, $currency);
    }

    /**
     * @param  array<int, Wallet>  $wallets
     * @return array<int, WalletHold>
     */
    private function lockCaptures(PendingTransaction $pending, array $wallets): array
    {
        if ($pending->captures === []) {
            return [];
        }

        $ids = array_unique(array_map(fn (WalletHold $hold) => $hold->id, $pending->captures));
        sort($ids);

        $holds = WalletHold::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();

        foreach ($ids as $id) {
            $hold = $holds[$id] ?? throw new LogicException("Unknown hold {$id}.");

            if ($hold->status !== HoldStatus::Active) {
                throw new LogicException("Hold {$id} is {$hold->status->value}, not active.");
            }

            $debited = array_filter(
                $pending->lines,
                fn (array $line) => $line['wallet']->id === $hold->wallet_id && $line['amount']->isNegative(),
            );

            if (! isset($wallets[$hold->wallet_id]) || $debited === []) {
                throw new LogicException("Hold {$id} can only be captured by a transaction that debits its wallet.");
            }
        }

        return $holds;
    }

    /**
     * @param  list<array{wallet: Wallet, amount: Money, component: mixed, fee_rule_id: int|null, company_agreement_id: int|null, bonus: int|null}>  $lines
     * @param  array<int, Wallet>  $wallets
     * @param  array<int, int>  $released  minor units freed by captured holds, by wallet id
     */
    private function assertCovered(array $lines, array $wallets, array $released): void
    {
        $net = [];

        foreach ($lines as $line) {
            $net[$line['wallet']->id] = ($net[$line['wallet']->id] ?? 0) + $line['amount']->amount;
        }

        foreach ($net as $id => $change) {
            $wallet = $wallets[$id];

            if ($change >= 0 || $wallet->type === WalletType::System) {
                continue;
            }

            $spendable = $wallet->available()->plus(Money::minor($released[$id] ?? 0, $wallet->currency));

            if ($spendable->amount + $change < 0) {
                throw new InsufficientFundsException($id, $spendable, Money::minor(-$change, $wallet->currency));
            }
        }
    }

    /**
     * The transaction already posted under the pending one's key. A locking
     * read sees rows committed after this transaction's snapshot was taken.
     */
    private function existing(PendingTransaction $pending, bool $locking): ?LedgerTransaction
    {
        $query = LedgerTransaction::query()->where('idempotency_key', $pending->idempotencyKey);

        $existing = ($locking ? $query->lockForUpdate() : $query)->first();

        if ($existing !== null && $existing->type !== $pending->type) {
            throw new LogicException("Idempotency key [{$pending->idempotencyKey}] was used for a {$existing->type->value} transaction.");
        }

        return $existing;
    }

    /** Brings a model the caller holds up to date with the locked copy that was written. */
    private function sync(Model $original, Model $fresh): void
    {
        if ($original !== $fresh) {
            $original->setRawAttributes($fresh->getAttributes(), true);
        }
    }
}
