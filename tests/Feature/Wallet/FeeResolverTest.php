<?php

namespace Tests\Feature\Wallet;

use App\Enums\CommissionBasis;
use App\Enums\FeeChargeType;
use App\Enums\FeeUseCase;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\FeeRule;
use App\Models\UserType;
use App\Services\Fees\CommissionTerms;
use App\Services\Fees\FeeResolver;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeeResolverTest extends TestCase
{
    use RefreshDatabase;

    private FeeResolver $fees;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fees = app(FeeResolver::class);
    }

    public function test_with_no_rule_there_is_no_charge(): void
    {
        $fee = $this->fees->charge(FeeUseCase::Deposit, FeeChargeType::Service, Money::minor(100_000));

        $this->assertTrue($fee->amount->isZero());
        $this->assertNull($fee->rule);
    }

    public function test_the_charge_is_percent_plus_fixed_clamped_between_min_and_max(): void
    {
        FeeRule::factory()->create(['percent_bps' => 150, 'fixed_amount' => 500, 'min_amount' => 1_000, 'max_amount' => 50_000]);

        $charge = fn (int $amount) => $this->fees->charge(FeeUseCase::Deposit, FeeChargeType::Service, Money::minor($amount))->amount->amount;

        $this->assertSame(1_000, $charge(10_000));          // 150 + 500, raised to the minimum
        $this->assertSame(2_000, $charge(100_000));         // 1,500 + 500
        $this->assertSame(1_501, $charge(66_700));          // 1,000.5 rounds half up, + 500
        $this->assertSame(50_000, $charge(10_000_000));     // capped
    }

    public function test_a_rule_for_the_payers_user_type_beats_the_general_one(): void
    {
        $premium = UserType::factory()->create();
        FeeRule::factory()->create(['percent_bps' => 100]);
        $specific = FeeRule::factory()->create(['percent_bps' => 50, 'user_type_id' => $premium->id]);

        $this->assertSame($specific->id, $this->fees->rule(FeeUseCase::Deposit, FeeChargeType::Service, $premium->id)?->id);
        $this->assertSame(100, $this->fees->rule(FeeUseCase::Deposit, FeeChargeType::Service, null)?->percent_bps);
        $this->assertSame(100, $this->fees->rule(FeeUseCase::Deposit, FeeChargeType::Service, UserType::factory()->create()->id)?->percent_bps);
    }

    public function test_a_gateway_rule_applies_only_to_its_gateway(): void
    {
        FeeRule::factory()->create(['charge_type' => FeeChargeType::Gateway, 'gateway' => 'sslcommerz', 'percent_bps' => 250]);
        FeeRule::factory()->create(['charge_type' => FeeChargeType::Gateway, 'gateway' => null, 'percent_bps' => 300]);

        $this->assertSame(250, $this->fees->rule(FeeUseCase::Deposit, FeeChargeType::Gateway, null, 'sslcommerz')?->percent_bps);
        $this->assertSame(300, $this->fees->rule(FeeUseCase::Deposit, FeeChargeType::Gateway, null, 'stripe')?->percent_bps);
        $this->assertSame(300, $this->fees->rule(FeeUseCase::Deposit, FeeChargeType::Gateway)?->percent_bps);
    }

    public function test_only_active_rules_in_force_count_and_the_newest_wins(): void
    {
        FeeRule::factory()->create(['percent_bps' => 100, 'effective_from' => now()->subMonth()]);
        FeeRule::factory()->create(['percent_bps' => 200, 'effective_from' => now()->subWeek()]);
        FeeRule::factory()->create(['percent_bps' => 300, 'effective_from' => now()->addDay()]);
        FeeRule::factory()->create(['percent_bps' => 400, 'is_active' => false]);
        FeeRule::factory()->create(['percent_bps' => 500, 'effective_from' => now()->subYear(), 'effective_until' => now()->subMonth()]);

        $this->assertSame(200, $this->fees->rule(FeeUseCase::Deposit, FeeChargeType::Service)?->percent_bps);
        $this->assertSame(100, $this->fees->rule(FeeUseCase::Deposit, FeeChargeType::Service, at: now()->subWeeks(2))?->percent_bps);
        $this->assertSame(300, $this->fees->rule(FeeUseCase::Deposit, FeeChargeType::Service, at: now()->addDays(2))?->percent_bps);
    }

    public function test_charges_returns_the_gateway_and_service_charge(): void
    {
        FeeRule::factory()->create(['charge_type' => FeeChargeType::Gateway, 'gateway' => 'fake', 'percent_bps' => 250]);
        FeeRule::factory()->create(['charge_type' => FeeChargeType::Service, 'percent_bps' => 100]);

        $charges = $this->fees->charges(FeeUseCase::Deposit, Money::minor(100_000), null, 'fake');

        $this->assertSame(2_500, $charges['gateway']->amount->amount);
        $this->assertSame(1_000, $charges['service']->amount->amount);
    }

    public function test_a_companys_agreement_sets_its_commission(): void
    {
        $company = Company::factory()->create();
        FeeRule::factory()->create(['use_case' => FeeUseCase::SaleCommission, 'percent_bps' => 1_000]);
        $agreement = CompanyAgreement::factory()->create(['company_id' => $company->id, 'rate_bps' => 750]);

        $terms = $this->fees->saleCommission($company);

        $this->assertSame(750, $terms->rateBps);
        $this->assertSame($agreement->id, $terms->companyAgreementId);
        $this->assertSame(7_500, $terms->apply(Money::minor(100_000), Money::minor(50_000))->amount);
    }

    public function test_without_an_agreement_the_platform_rule_applies(): void
    {
        $company = Company::factory()->create();
        $rule = FeeRule::factory()->create(['use_case' => FeeUseCase::SaleCommission, 'percent_bps' => 1_000]);

        // An agreement that has not started yet does not count.
        CompanyAgreement::factory()->create(['company_id' => $company->id, 'rate_bps' => 500, 'effective_from' => now()->addWeek()]);

        $terms = $this->fees->saleCommission($company);

        $this->assertSame(1_000, $terms->rateBps);
        $this->assertSame($rule->id, $terms->feeRuleId);
        $this->assertNull($terms->companyAgreementId);
    }

    public function test_with_no_agreement_or_rule_there_is_no_commission(): void
    {
        $terms = $this->fees->saleCommission(Company::factory()->create());

        $this->assertTrue($terms->apply(Money::minor(100_000), Money::minor(1_000))->isZero());
    }

    public function test_commission_on_the_amount_above_the_starting_price_never_exceeds_the_price(): void
    {
        $terms = new CommissionTerms(1_000, Money::minor(2_000), null, null, CommissionBasis::AboveStartingPrice);

        $this->assertSame(7_000, $terms->apply(Money::minor(100_000), Money::minor(50_000))->amount);
        $this->assertSame(2_000, $terms->apply(Money::minor(40_000), Money::minor(50_000))->amount);
        $this->assertSame(1_500, $terms->apply(Money::minor(1_500), Money::minor(1_500))->amount);
    }

    public function test_commission_terms_survive_the_auction_snapshot(): void
    {
        $terms = new CommissionTerms(825, Money::minor(100), Money::minor(1_000), Money::minor(90_000), CommissionBasis::FinalPrice, 4, null);

        $copy = CommissionTerms::fromSnapshot(json_decode((string) json_encode($terms->toSnapshot()), true));

        $this->assertEquals($terms, $copy);
    }
}
