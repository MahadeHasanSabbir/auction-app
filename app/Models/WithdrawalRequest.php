<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PayoutMethod;
use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request to pay money out of a wallet. The amount is held while the
 * request is open (pending or approved) and leaves the wallet when staff
 * mark it paid. See App\Actions\Wallet\*Withdrawal.
 */
class WithdrawalRequest extends Model
{
    /**
     * Every column decides money or a review, so nothing is mass assignable;
     * the withdrawal actions use forceFill().
     *
     * @var list<string>
     */
    protected $fillable = [];

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
            'method' => PayoutMethod::class,
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

    /** @return HasMany<WalletHold, $this> */
    public function holds(): HasMany
    {
        return $this->hasMany(WalletHold::class);
    }

    /** The payout destination in a form safe to show, for example "bKash ••5678". */
    public function destinationSummary(): string
    {
        return $this->method->describe($this->destination);
    }
}
