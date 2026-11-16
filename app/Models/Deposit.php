<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\DepositStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deposit extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'wallet_id', 'user_id', 'gateway', 'tran_id', 'gateway_reference',
        'amount', 'gateway_charge', 'service_charge', 'net_amount', 'currency',
        'status', 'ledger_transaction_id', 'gateway_payload', 'failure_reason', 'completed_at',
    ];

    /** @var list<string> */
    protected $hidden = ['gateway_payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'gateway_charge' => MoneyCast::class,
            'service_charge' => MoneyCast::class,
            'net_amount' => MoneyCast::class,
            'status' => DepositStatus::class,
            'gateway_payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<LedgerTransaction, $this> */
    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }
}
