<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawalRequest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'wallet_id', 'requested_by', 'amount', 'gateway_charge', 'service_charge', 'net_amount', 'currency',
        'method', 'destination', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason',
        'payout_reference', 'paid_at', 'ledger_transaction_id',
    ];

    /** @var list<string> */
    protected $hidden = ['destination'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'gateway_charge' => MoneyCast::class,
            'service_charge' => MoneyCast::class,
            'net_amount' => MoneyCast::class,
            // Bank or mobile-wallet details, encrypted at rest.
            'destination' => 'encrypted:array',
            'status' => WithdrawalStatus::class,
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<LedgerTransaction, $this> */
    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }
}
