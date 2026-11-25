<?php

namespace App\Services\Fees;

use App\Enums\CommissionBasis;
use App\Support\Money;

/**
 * The sale commission a company pays, as fixed on an auction when it is
 * approved (auctions.fee_snapshot['sale_commission']), so a later change of
 * agreement or fee rule does not affect a running auction.
 */
final readonly class CommissionTerms
{
    public function __construct(
        public int $rateBps,
        public Money $fixed,
        public ?Money $min,
        public ?Money $max,
        public CommissionBasis $basis,
        public ?int $companyAgreementId = null,
        public ?int $feeRuleId = null,
    ) {}

    public static function none(): self
    {
        return new self(0, Money::zero(), null, null, CommissionBasis::FinalPrice);
    }

    /** For people: "5% + ৳50.00 of the final price (min ৳100.00)". */
    public function describe(): string
    {
        $rate = rtrim(rtrim(number_format($this->rateBps / 100, 2, '.', ''), '0'), '.').'%';
        $parts = $this->fixed->isZero() ? $rate : $rate.' + '.$this->fixed->format();
        $limits = array_filter([
            $this->min ? __('min :amount', ['amount' => $this->min->format()]) : null,
            $this->max ? __('max :amount', ['amount' => $this->max->format()]) : null,
        ]);

        return __(':terms of the :basis', ['terms' => $parts, 'basis' => mb_strtolower($this->basis->label())])
            .($limits === [] ? '' : ' ('.implode(', ', $limits).')');
    }

    /** The commission on a sale; never more than the price itself. */
    public function apply(Money $finalPrice, Money $startingPrice): Money
    {
        $base = match ($this->basis) {
            CommissionBasis::FinalPrice => $finalPrice,
            CommissionBasis::AboveStartingPrice => $finalPrice->minus($startingPrice)->clamp(Money::zero($finalPrice->currency), null),
        };

        return Fee::calculate($base, $this->rateBps, $this->fixed, $this->min, $this->max)
            ->clamp(null, $finalPrice);
    }

    /**
     * @return array{rate_bps: int, fixed_amount: int, min_amount: int|null, max_amount: int|null, basis: string, company_agreement_id: int|null, fee_rule_id: int|null}
     */
    public function toSnapshot(): array
    {
        return [
            'rate_bps' => $this->rateBps,
            'fixed_amount' => $this->fixed->amount,
            'min_amount' => $this->min?->amount,
            'max_amount' => $this->max?->amount,
            'basis' => $this->basis->value,
            'company_agreement_id' => $this->companyAgreementId,
            'fee_rule_id' => $this->feeRuleId,
        ];
    }

    /** @param  array<string, mixed>  $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        $money = fn (mixed $value): ?Money => $value === null ? null : Money::minor((int) $value);

        return new self(
            rateBps: (int) ($snapshot['rate_bps'] ?? 0),
            fixed: Money::minor((int) ($snapshot['fixed_amount'] ?? 0)),
            min: $money($snapshot['min_amount'] ?? null),
            max: $money($snapshot['max_amount'] ?? null),
            basis: CommissionBasis::from((string) ($snapshot['basis'] ?? CommissionBasis::FinalPrice->value)),
            companyAgreementId: isset($snapshot['company_agreement_id']) ? (int) $snapshot['company_agreement_id'] : null,
            feeRuleId: isset($snapshot['fee_rule_id']) ? (int) $snapshot['fee_rule_id'] : null,
        );
    }
}
