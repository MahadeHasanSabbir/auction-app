<?php

namespace App\Filament\Support\Reports;

use App\Models\Deposit;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\SubscriptionInvoice;
use App\Models\WithdrawalRequest;

/** What a ledger transaction was for, as a short reference (needs `reference` loaded). */
final class LedgerReference
{
    public static function label(?LedgerTransaction $transaction): ?string
    {
        return match (true) {
            $transaction === null => null,
            $transaction->reference instanceof Order => $transaction->reference->number,
            $transaction->reference instanceof Deposit => $transaction->reference->tran_id,
            $transaction->reference instanceof SubscriptionInvoice => $transaction->reference->number,
            $transaction->reference instanceof WithdrawalRequest => __('Withdrawal #:id', ['id' => $transaction->reference->id]),
            default => $transaction->description,
        };
    }
}
