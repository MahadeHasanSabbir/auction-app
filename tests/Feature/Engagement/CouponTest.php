<?php

namespace Tests\Feature\Engagement;

use App\Actions\Auctions\FeatureAuction;
use App\Actions\Bidding\BuyNow;
use App\Actions\Billing\ApplyInvoiceCoupon;
use App\Actions\Billing\PayInvoice;
use App\Actions\Billing\SubscribeToPlan;
use App\Actions\Coupons\SaveCoupon;
use App\Actions\Orders\ApplyOrderCoupon;
use App\Actions\Orders\CompleteOrder;
use App\Actions\Orders\ShipOrder;
use App\Enums\BillingInterval;
use App\Enums\CommissionBasis;
use App\Enums\CouponDiscountType;
use App\Enums\CouponTarget;
use App\Enums\FeeUseCase;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\SystemAccount;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\FeeRule;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fees\CommissionTerms;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** Platform coupons on plan invoices and the featured fee, and seller coupons on orders. */
class CouponTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Wallet $companyWallet;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->createPlatformAccounts();
        [$this->company, $this->owner, $this->companyWallet] = $this->company();
    }

    private function refused(callable $call, string $key = 'code'): void
    {
        try {
            $call();
            $this->fail('The call was not refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    private function auction(int $buyNow = 200_000): Auction
    {
        $terms = new CommissionTerms(1_000, Money::zero(), null, null, CommissionBasis::FinalPrice);

        return Auction::factory()->live()->create([
            'company_id' => $this->company->id,
            'starting_price' => 100_000,
            'buy_now_price' => $buyNow,
            'ends_at' => now()->addDay(),
            'fee_snapshot' => ['sale_commission' => $terms->toSnapshot()],
        ]);
    }

    public function test_a_plan_coupon_lowers_the_invoice_once(): void
    {
        $this->fund($this->companyWallet, 500_000);
        ['invoice' => $invoice] = app(SubscribeToPlan::class)->handle($this->owner, $this->company, $this->plan(), BillingInterval::Monthly);
        Coupon::factory()->create(['code' => 'PLAN25', 'value' => 2_500]);

        app(ApplyInvoiceCoupon::class)->handle($this->owner, $invoice, 'plan25');

        $invoice->refresh();
        $this->assertSame(75_000, $invoice->amount->amount);
        $this->assertSame(25_000, $invoice->discount->amount);
        $this->refused(fn () => app(ApplyInvoiceCoupon::class)->handle($this->owner, $invoice, 'PLAN25'));

        app(PayInvoice::class)->fromWallet($invoice);

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(425_000, $this->companyWallet->refresh()->balance->amount);
        $this->assertSame(75_000, Invoice::query()->where('kind', InvoiceKind::Subscription)->sole()->total->amount);
        $this->assertLedgerBalanced();
    }

    public function test_a_member_cannot_apply_a_plan_coupon(): void
    {
        ['invoice' => $invoice] = app(SubscribeToPlan::class)->handle($this->owner, $this->company, $this->plan(), BillingInterval::Monthly);
        Coupon::factory()->create(['code' => 'PLAN25']);
        $member = User::factory()->companyMember($this->company)->create();

        $this->expectException(AuthorizationException::class);
        app(ApplyInvoiceCoupon::class)->handle($member, $invoice, 'PLAN25');
    }

    public function test_featuring_charges_the_fee_less_a_coupon_and_issues_an_invoice(): void
    {
        FeeRule::factory()->create(['use_case' => FeeUseCase::FeaturedListing, 'percent_bps' => 0, 'fixed_amount' => 20_000]);
        Coupon::factory()->featured()->fixed(5_000)->create(['code' => 'SHINE']);
        $this->fund($this->companyWallet, 100_000);
        $auction = $this->auction();

        app(FeatureAuction::class)->handle($this->owner, $auction, 'SHINE');

        $this->assertNotNull($auction->refresh()->featured_at);
        $this->assertSame(85_000, $this->companyWallet->refresh()->balance->amount);
        $this->assertSame(15_000, $this->system(SystemAccount::ServiceRevenue)->balance->amount);
        $this->assertSame(15_000, Invoice::query()->where('kind', InvoiceKind::Featured)->sole()->total->amount);
        $this->assertSame(1, Coupon::query()->sole()->used_count);
        $this->refused(fn () => app(FeatureAuction::class)->handle($this->owner, $auction), 'auction');
        $this->assertLedgerBalanced();
    }

    public function test_featuring_needs_a_fee_rule_the_money_and_the_owner(): void
    {
        $auction = $this->auction();

        $this->refused(fn () => app(FeatureAuction::class)->handle($this->owner, $auction), 'auction');

        FeeRule::factory()->create(['use_case' => FeeUseCase::FeaturedListing, 'percent_bps' => 0, 'fixed_amount' => 20_000]);
        $this->refused(fn () => app(FeatureAuction::class)->handle($this->owner, $auction), 'auction');
        $this->assertNull($auction->refresh()->featured_at);

        $this->expectException(AuthorizationException::class);
        app(FeatureAuction::class)->handle(User::factory()->companyMember($this->company)->create(), $auction);
    }

    public function test_a_seller_coupon_returns_the_discount_and_the_company_bears_it(): void
    {
        $buyer = User::factory()->create(['address' => 'House 1, Road 2, Dhaka']);
        $buyerWallet = $this->fund(Wallet::factory()->create(['user_id' => $buyer->id]), 500_000);
        Coupon::factory()->forCompany($this->company)->create(['code' => 'THANKS', 'value' => 1_500]);

        $order = app(BuyNow::class)->handle($buyer, $this->auction(200_000));
        app(ApplyOrderCoupon::class)->handle($buyer, $order, 'thanks');

        $order->refresh();
        $this->assertSame(30_000, $order->discount->amount);
        $this->assertSame(20_000, $order->commission->amount);
        $this->assertSame(150_000, $order->seller_payout->amount);
        $this->assertSame(330_000, $buyerWallet->refresh()->balance->amount);
        $this->assertSame(170_000, $this->system(SystemAccount::Escrow)->balance->amount);
        $this->assertLedgerBalanced();

        app(ShipOrder::class)->handle($this->owner, $order, ['courier' => 'Sundarban', 'tracking_number' => 'SB-1']);
        app(CompleteOrder::class)->confirm($buyer, $order);

        $order->refresh();
        $this->assertSame(20_000, $order->commission->amount);
        $this->assertSame(150_000, $order->seller_payout->amount);
        $this->assertSame(150_000, $this->companyWallet->refresh()->balance->amount);
        $this->assertSame(20_000, $this->system(SystemAccount::CommissionRevenue)->balance->amount);
        $this->assertSame(0, $this->system(SystemAccount::Escrow)->balance->amount);
        $this->assertLedgerBalanced();
    }

    public function test_a_seller_coupon_never_eats_into_the_commission(): void
    {
        $buyer = User::factory()->create(['address' => 'House 1, Road 2, Dhaka']);
        $this->fund(Wallet::factory()->create(['user_id' => $buyer->id]), 500_000);
        Coupon::factory()->forCompany($this->company)->create(['code' => 'ALL', 'value' => 10_000]);

        $order = app(BuyNow::class)->handle($buyer, $this->auction(200_000));
        app(ApplyOrderCoupon::class)->handle($buyer, $order, 'ALL');

        $this->assertSame(180_000, $order->refresh()->discount->amount);
        $this->assertSame(0, $order->seller_payout->amount);
        $this->assertLedgerBalanced();
    }

    public function test_a_seller_coupon_works_only_on_that_company_and_before_shipping(): void
    {
        $buyer = User::factory()->create(['address' => 'House 1, Road 2, Dhaka']);
        $this->fund(Wallet::factory()->create(['user_id' => $buyer->id]), 500_000);
        Coupon::factory()->forCompany()->create(['code' => 'OTHER']);
        Coupon::factory()->forCompany($this->company)->create(['code' => 'MINE']);

        $order = app(BuyNow::class)->handle($buyer, $this->auction());
        $this->refused(fn () => app(ApplyOrderCoupon::class)->handle($buyer, $order, 'OTHER'));

        app(ShipOrder::class)->handle($this->owner, $order, ['courier' => 'Sundarban', 'tracking_number' => 'SB-1']);

        $this->expectException(AuthorizationException::class);
        app(ApplyOrderCoupon::class)->handle($buyer, $order->refresh(), 'MINE');
    }

    public function test_coupons_are_saved_by_the_right_people(): void
    {
        $staff = User::factory()->platformStaff()->create();
        $data = ['code' => 'spring-10', 'target' => CouponTarget::PlanInvoice->value, 'discount_type' => CouponDiscountType::Percent->value, 'value' => 1_000, 'per_user_limit' => 1, 'is_active' => true];

        $platform = app(SaveCoupon::class)->handle($staff, $data);
        $this->assertSame('SPRING-10', $platform->code);
        $this->assertNull($platform->company_id);

        $seller = app(SaveCoupon::class)->handle($this->owner, ['code' => 'SELLER5'] + $data, $this->company);
        $this->assertSame(CouponTarget::Order, $seller->target);
        $this->assertSame($this->company->id, $seller->company_id);

        $this->refused(fn () => app(SaveCoupon::class)->handle($staff, $data), 'code');
        $this->refused(fn () => app(SaveCoupon::class)->handle($staff, ['code' => 'BIG', 'value' => 20_000] + $data), 'value');
        $this->refused(fn () => app(SaveCoupon::class)->handle($staff, ['code' => 'BONUSPCT', 'target' => CouponTarget::WalletBonus->value] + $data), 'discount_type');

        $this->expectException(AuthorizationException::class);
        app(SaveCoupon::class)->handle(User::factory()->companyMember($this->company)->create(), ['code' => 'NOPE'] + $data, $this->company);
    }

    public function test_a_used_coupon_keeps_its_code(): void
    {
        $staff = User::factory()->platformStaff()->create();
        $coupon = Coupon::factory()->create(['code' => 'USED', 'used_count' => 1]);

        $this->refused(fn () => app(SaveCoupon::class)->handle($staff, ['code' => 'RENAMED', 'target' => 'plan_invoice', 'discount_type' => 'percent', 'value' => 500, 'per_user_limit' => 1], coupon: $coupon));
        $this->assertFalse($staff->can('delete', $coupon));
    }
}
