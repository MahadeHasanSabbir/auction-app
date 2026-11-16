<?php

namespace App\Services\Fees;

use App\Enums\CommissionBasis;
use App\Enums\FeeChargeType;
use App\Enums\FeeUseCase;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\FeeRule;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Works out the charges set by the platform.
 *
 * For a use case and charge type, the most specific active rule wins: a rule
 * for the payer's user type beats one for every type, then a rule for the
 * gateway beats one for every gateway, then the newest rule wins. A rule
 * naming a user type or gateway applies only to that type or gateway.
 *
 *     fee = clamp(amount × percent + fixed, min, max)
 */
final class FeeResolver
{
    public function rule(FeeUseCase $useCase, FeeChargeType $chargeType, ?int $userTypeId = null, ?string $gateway = null, ?CarbonInterface $at = null): ?FeeRule
    {
        return FeeRule::query()
            ->effectiveAt($at)
            ->where('use_case', $useCase)
            ->where('charge_type', $chargeType)
            ->where(fn ($q) => $q->whereNull('user_type_id')->when($userTypeId !== null, fn ($q) => $q->orWhere('user_type_id', $userTypeId)))
            ->where(fn ($q) => $q->whereNull('gateway')->when($gateway !== null, fn ($q) => $q->orWhere('gateway', $gateway)))
            ->orderByRaw('user_type_id is null')
            ->orderByRaw('gateway is null')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function charge(FeeUseCase $useCase, FeeChargeType $chargeType, Money $amount, ?int $userTypeId = null, ?string $gateway = null, ?CarbonInterface $at = null): Fee
    {
        $rule = $this->rule($useCase, $chargeType, $userTypeId, $gateway, $at);

        if ($rule === null) {
            return Fee::none($amount->currency);
        }

        return new Fee(
            Fee::calculate($amount, $rule->percent_bps, $rule->fixed_amount, $rule->min_amount, $rule->max_amount),
            $rule,
        );
    }

    /**
     * The gateway charge and the service charge on a deposit or withdrawal.
     *
     * @return array{gateway: Fee, service: Fee}
     */
    public function charges(FeeUseCase $useCase, Money $amount, ?int $userTypeId, ?string $gateway, ?CarbonInterface $at = null): array
    {
        return [
            'gateway' => $this->charge($useCase, FeeChargeType::Gateway, $amount, $userTypeId, $gateway, $at),
            'service' => $this->charge($useCase, FeeChargeType::Service, $amount, $userTypeId, null, $at),
        ];
    }

    /**
     * The sale commission a company pays: its own agreement first (set by
     * the system owner), else the platform's sale-commission rule for its
     * user type, else nothing.
     */
    public function saleCommission(Company $company, ?CarbonInterface $at = null): CommissionTerms
    {
        $agreement = CompanyAgreement::query()
            ->where('company_id', $company->id)
            ->where('use_case', FeeUseCase::SaleCommission)
            ->effectiveAt($at)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($agreement !== null) {
            return new CommissionTerms(
                rateBps: $agreement->rate_bps,
                fixed: $agreement->fixed_amount,
                min: $agreement->min_amount,
                max: $agreement->max_amount,
                basis: $agreement->basis,
                companyAgreementId: $agreement->id,
            );
        }

        $rule = $this->rule(FeeUseCase::SaleCommission, FeeChargeType::Service, $company->user_type_id, null, $at);

        if ($rule === null) {
            return CommissionTerms::none();
        }

        return new CommissionTerms(
            rateBps: $rule->percent_bps,
            fixed: $rule->fixed_amount,
            min: $rule->min_amount,
            max: $rule->max_amount,
            basis: CommissionBasis::FinalPrice,
            feeRuleId: $rule->id,
        );
    }
}
