<?php

namespace App\Services\Ledger;

use App\Enums\LedgerComponent;
use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Models\CompanyAgreement;
use App\Models\FeeRule;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Services\Fees\CommissionTerms;
use App\Services\Fees\Fee;
use App\Support\Money;
use App\Support\Vat;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A ledger transaction being put together. Nothing is written until post().
 *
 *     $ledger->transaction(LedgerTransactionType::Deposit)
 *         ->reference($deposit)
 *         ->idempotent("deposit:{$deposit->id}")
 *         ->credit($buyerWallet, $net)
 *         ->credit($ledger->system(SystemAccount::ServiceRevenue), $fee, LedgerComponent::ServiceCharge, $rule)
 *         ->debit($ledger->system(SystemAccount::GatewayClearing), $amount)
 *         ->post();
 */
final class PendingTransaction
{
    /**
     * bonus: the bonus-credit part of a credit, in minor units; null lets
     * the ledger work out a debit's part.
     *
     * @var list<array{wallet: Wallet, amount: Money, component: LedgerComponent, fee_rule_id: int|null, company_agreement_id: int|null, bonus: int|null}>
     */
    public private(set) array $lines = [];

    /** Whether debits of member wallets spend bonus credit before real money. */
    public private(set) bool $bonusFirst = false;

    /** @var list<WalletHold> */
    public private(set) array $captures = [];

    public private(set) ?Model $reference = null;

    public private(set) ?string $description = null;

    public private(set) ?string $idempotencyKey = null;

    public private(set) ?int $createdBy = null;

    /** @var array<string, mixed> */
    public private(set) array $meta = [];

    public function __construct(
        private readonly Ledger $ledger,
        public readonly LedgerTransactionType $type,
    ) {}

    /**
     * Adds money to a wallet. A zero amount is skipped. $source is the fee
     * rule or agreement that produced a charge, recorded on the entry.
     */
    public function credit(Wallet $wallet, Money $amount, LedgerComponent $component = LedgerComponent::Principal, FeeRule|CompanyAgreement|Fee|CommissionTerms|null $source = null): self
    {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('Credit a positive amount; use debit() to take money out.');
        }

        return $this->line($wallet, $amount, $component, $source);
    }

    /**
     * Credits a platform fee that includes VAT: the VAT part goes to the
     * tax-payable account as its own entry, the rest to $wallet. The rate is
     * recorded in the transaction's meta.
     */
    public function creditWithVat(Wallet $wallet, Money $gross, LedgerComponent $component, FeeRule|CompanyAgreement|Fee|CommissionTerms|null $source = null): self
    {
        $rate = Vat::rate();
        [$net, $tax] = Vat::split($gross, $rate);

        $this->meta['vat_bps'] = $rate;

        return $this->credit($wallet, $net, $component, $source)
            ->credit($this->ledger->system(SystemAccount::TaxPayable), $tax, LedgerComponent::Tax);
    }

    /**
     * Adds money to a member wallet of which $bonus (all of it by default)
     * is bonus credit: spendable on purchases, never withdrawable.
     */
    public function creditBonus(Wallet $wallet, Money $amount, ?Money $bonus = null): self
    {
        $bonus ??= $amount;

        if ($amount->isNegative() || $bonus->isNegative() || $bonus->greaterThan($amount)) {
            throw new InvalidArgumentException('The bonus part of a credit lies between zero and the amount.');
        }

        $this->line($wallet, $amount, LedgerComponent::Principal, null);

        if (! $amount->isZero()) {
            $this->lines[array_key_last($this->lines)]['bonus'] = $bonus->amount;
        }

        return $this;
    }

    /**
     * Debits of member wallets in this transaction spend bonus credit first
     * (purchases). Otherwise real money goes first and bonus credit is only
     * touched when the real money runs out.
     */
    public function spendingBonusFirst(): self
    {
        $this->bonusFirst = true;

        return $this;
    }

    /** Takes money out of a wallet. A zero amount is skipped. */
    public function debit(Wallet $wallet, Money $amount, LedgerComponent $component = LedgerComponent::Principal, FeeRule|CompanyAgreement|Fee|CommissionTerms|null $source = null): self
    {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('Debit a positive amount; use credit() to put money in.');
        }

        return $this->line($wallet, $amount->negated(), $component, $source);
    }

    /**
     * Consumes an active hold in the same transaction: the hold is marked
     * captured and stops counting against the wallet, so the debit it
     * reserved money for is paid from the held amount.
     */
    public function capturing(WalletHold $hold): self
    {
        $this->captures[] = $hold;

        return $this;
    }

    /** What the money moved for: a deposit, an order, a withdrawal request, … */
    public function reference(Model $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Posting twice with the same key posts once; the second call returns the
     * first transaction.
     */
    public function idempotent(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /** The person who caused the movement (staff for manual actions). */
    public function by(?User $user): self
    {
        $this->createdBy = $user?->id;

        return $this;
    }

    /** @param  array<string, mixed>  $meta */
    public function meta(array $meta): self
    {
        $this->meta = $meta + $this->meta;

        return $this;
    }

    public function post(): LedgerTransaction
    {
        return $this->ledger->post($this);
    }

    private function line(Wallet $wallet, Money $amount, LedgerComponent $component, FeeRule|CompanyAgreement|Fee|CommissionTerms|null $source): self
    {
        if (! $amount->isZero()) {
            $this->lines[] = [
                'wallet' => $wallet,
                'amount' => $amount,
                'component' => $component,
                'fee_rule_id' => match (true) {
                    $source instanceof FeeRule => $source->id,
                    $source instanceof Fee => $source->rule?->id,
                    $source instanceof CommissionTerms => $source->feeRuleId,
                    default => null,
                },
                'company_agreement_id' => match (true) {
                    $source instanceof CompanyAgreement => $source->id,
                    $source instanceof CommissionTerms => $source->companyAgreementId,
                    default => null,
                },
                'bonus' => null,
            ];
        }

        return $this;
    }
}
