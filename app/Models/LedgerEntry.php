<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\LedgerComponent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a ledger transaction. Positive amounts credit the wallet,
 * negative amounts debit it. Append-only.
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'ledger_transaction_id', 'wallet_id', 'amount', 'component',
        'fee_rule_id', 'company_agreement_id', 'balance_after', 'bonus',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'balance_after' => MoneyCast::class,
            'bonus' => MoneyCast::class,
            'component' => LedgerComponent::class,
        ];
    }

    /** @return BelongsTo<LedgerTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<FeeRule, $this> */
    public function feeRule(): BelongsTo
    {
        return $this->belongsTo(FeeRule::class);
    }

    /** @return BelongsTo<CompanyAgreement, $this> */
    public function companyAgreement(): BelongsTo
    {
        return $this->belongsTo(CompanyAgreement::class);
    }
}
