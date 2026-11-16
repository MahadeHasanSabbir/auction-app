<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\DepositStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deposit extends Model
{
    /**
     * Every column is money or gateway state, set only by the deposit actions
     * (App\Actions\Wallet) with forceFill().
     *
     * @var list<string>
     */
    protected $fillable = [];

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

    /** @return BelongsTo<FeeRule, $this> */
    public function gatewayFeeRule(): BelongsTo
    {
        return $this->belongsTo(FeeRule::class, 'gateway_fee_rule_id');
    }

    /** @return BelongsTo<FeeRule, $this> */
    public function serviceFeeRule(): BelongsTo
    {
        return $this->belongsTo(FeeRule::class, 'service_fee_rule_id');
    }

    /** @return BelongsTo<LedgerTransaction, $this> */
    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }
}
