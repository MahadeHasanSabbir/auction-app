<?php

namespace App\Services\Ledger;

use App\Enums\AuctionStatus;
use App\Enums\HoldStatus;
use App\Enums\HoldType;
use App\Enums\OrderStatus;
use App\Enums\SystemAccount;
use App\Enums\WalletType;
use Illuminate\Support\Facades\DB;

/**
 * Checks that the books are consistent. Each problem is one line of text;
 * an empty list means everything adds up. Every check is one aggregate
 * query, so it is cheap enough to run after every money test and daily.
 */
final class LedgerVerifier
{
    /** @return list<string> */
    public function problems(): array
    {
        return [
            ...$this->unbalancedTransactions(),
            ...$this->singleEntryTransactions(),
            ...$this->balanceCaches(),
            ...$this->heldCaches(),
            ...$this->runningBalances(),
            ...$this->overdrawnWallets(),
            ...$this->foreignCurrencies(),
            ...$this->escrow(),
            ...$this->bidHolds(),
        ];
    }

    /** @return list<string> */
    private function unbalancedTransactions(): array
    {
        return DB::table('ledger_entries')
            ->select('ledger_transaction_id')
            ->selectRaw('sum(amount) as total')
            ->groupBy('ledger_transaction_id')
            ->havingRaw('sum(amount) <> 0')
            ->get()
            ->map(fn (object $row) => "Transaction {$row->ledger_transaction_id} sums to {$row->total}, not zero.")
            ->all();
    }

    /** @return list<string> */
    private function singleEntryTransactions(): array
    {
        return DB::table('ledger_transactions as t')
            ->leftJoin('ledger_entries as e', 'e.ledger_transaction_id', '=', 't.id')
            ->select('t.id')
            ->selectRaw('count(e.id) as entries')
            ->groupBy('t.id')
            ->havingRaw('count(e.id) < 2')
            ->get()
            ->map(fn (object $row) => "Transaction {$row->id} has {$row->entries} entries; at least two are needed.")
            ->all();
    }

    /** @return list<string> */
    private function balanceCaches(): array
    {
        $sums = DB::table('ledger_entries')
            ->select('wallet_id')
            ->selectRaw('sum(amount) as total')
            ->groupBy('wallet_id');

        return DB::table('wallets as w')
            ->leftJoinSub($sums, 's', 's.wallet_id', '=', 'w.id')
            ->whereRaw('w.balance <> coalesce(s.total, 0)')
            ->get(['w.id', 'w.balance', DB::raw('coalesce(s.total, 0) as total')])
            ->map(fn (object $row) => "Wallet {$row->id} caches a balance of {$row->balance}; its entries sum to {$row->total}.")
            ->all();
    }

    /** @return list<string> */
    private function heldCaches(): array
    {
        $sums = DB::table('wallet_holds')
            ->where('status', HoldStatus::Active)
            ->select('wallet_id')
            ->selectRaw('sum(amount) as total')
            ->groupBy('wallet_id');

        return DB::table('wallets as w')
            ->leftJoinSub($sums, 'h', 'h.wallet_id', '=', 'w.id')
            ->whereRaw('w.held <> coalesce(h.total, 0)')
            ->get(['w.id', 'w.held', DB::raw('coalesce(h.total, 0) as total')])
            ->map(fn (object $row) => "Wallet {$row->id} caches {$row->held} held; its active holds sum to {$row->total}.")
            ->all();
    }

    /**
     * The newest entry of every wallet records the balance the wallet has now.
     *
     * @return list<string>
     */
    private function runningBalances(): array
    {
        $latest = DB::table('ledger_entries')
            ->select('wallet_id')
            ->selectRaw('max(id) as id')
            ->groupBy('wallet_id');

        return DB::table('wallets as w')
            ->joinSub($latest, 'l', 'l.wallet_id', '=', 'w.id')
            ->join('ledger_entries as e', 'e.id', '=', 'l.id')
            ->whereColumn('e.balance_after', '<>', 'w.balance')
            ->get(['w.id', 'w.balance', 'e.balance_after'])
            ->map(fn (object $row) => "Wallet {$row->id} has a balance of {$row->balance}, but its last entry says {$row->balance_after}.")
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function overdrawnWallets(): array
    {
        return DB::table('wallets')
            ->where('type', '!=', WalletType::System)
            ->whereRaw('balance - cast(held as signed) < 0')
            ->get(['id', 'balance', 'held'])
            ->map(fn (object $row) => "Wallet {$row->id} is overdrawn: balance {$row->balance}, held {$row->held}.")
            ->all();
    }

    /** @return list<string> */
    private function foreignCurrencies(): array
    {
        return DB::table('wallets')
            ->where('currency', '!=', config('auction.currency'))
            ->get(['id', 'currency'])
            ->map(fn (object $row) => "Wallet {$row->id} is in {$row->currency}, not the marketplace currency.")
            ->all();
    }

    /**
     * Escrow holds exactly the money of the orders that are not finished.
     *
     * @return list<string>
     */
    private function escrow(): array
    {
        $open = array_map(
            fn (OrderStatus $status) => $status->value,
            array_filter(OrderStatus::cases(), fn (OrderStatus $status) => $status->holdsEscrow()),
        );

        $owed = (int) DB::table('orders')
            ->whereIn('status', $open)
            ->sum(DB::raw('amount - refunded_amount'));

        $balance = DB::table('wallets')->where('system_account', SystemAccount::Escrow)->value('balance');

        if ($balance === null) {
            return ['The escrow account does not exist.'];
        }

        return (int) $balance === $owed ? [] : ["Escrow has {$balance}; the open orders add up to {$owed}."];
    }

    /**
     * An active bid hold belongs to the leader of a live auction.
     *
     * @return list<string>
     */
    private function bidHolds(): array
    {
        return DB::table('wallet_holds as h')
            ->join('auctions as a', 'a.id', '=', 'h.auction_id')
            ->join('wallets as w', 'w.id', '=', 'h.wallet_id')
            ->where('h.status', HoldStatus::Active)
            ->where('h.type', HoldType::Bid)
            ->where(fn ($q) => $q->where('a.status', '!=', AuctionStatus::Live)
                ->orWhereNull('a.leader_id')
                ->orWhereNull('w.user_id')
                ->orWhereColumn('a.leader_id', '<>', 'w.user_id'))
            ->get(['h.id', 'h.auction_id'])
            ->map(fn (object $row) => "Hold {$row->id} on auction {$row->auction_id} does not belong to the leader of a live auction.")
            ->all();
    }
}
