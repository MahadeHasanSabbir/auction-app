<?php

namespace Tests\Feature\Engagement;

use App\Actions\Bidding\BuyNow;
use App\Actions\Orders\CancelOrder;
use App\Actions\Trust\IssueFine;
use App\Actions\Wallet\RedeemBonusCoupon;
use App\Actions\Wallet\RequestWithdrawal;
use App\Enums\CommissionBasis;
use App\Enums\LedgerTransactionType;
use App\Enums\PayoutMethod;
use App\Enums\SystemAccount;
use App\Enums\UserRole;
use App\Models\Auction;
use App\Models\Coupon;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fees\CommissionTerms;
use App\Services\Ledger\Ledger;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** Bonus credit: spendable on purchases first, never withdrawn, and given back as bonus credit on refunds. */
class BonusCreditTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    private User $buyer;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->createPlatformAccounts();
        $this->buyer = User::factory()->create();
        $this->wallet = Wallet::factory()->create(['user_id' => $this->buyer->id]);
    }

    private function bonus(int $minor): void
    {
        $ledger = app(Ledger::class);

        $ledger->transaction(LedgerTransactionType::Promotion)
            ->debit($ledger->system(SystemAccount::Promotions), Money::minor($minor))
            ->creditBonus($this->wallet, Money::minor($minor))
            ->post();
    }

    private function auction(int $buyNow): Auction
    {
        [$company] = $this->company();
        $terms = new CommissionTerms(1_000, Money::zero(), null, null, CommissionBasis::FinalPrice);

        return Auction::factory()->live()->create([
            'company_id' => $company->id,
            'starting_price' => 10_000,
            'buy_now_price' => $buyNow,
            'ends_at' => now()->addDay(),
            'fee_snapshot' => ['sale_commission' => $terms->toSnapshot()],
        ]);
    }

    public function test_a_bonus_code_credits_bonus_once_per_buyer(): void
    {
        Coupon::factory()->bonus(50_000)->create(['code' => 'WELCOME']);

        $amount = app(RedeemBonusCoupon::class)->handle($this->buyer, 'welcome');

        $this->assertSame(50_000, $amount->amount);
        $this->wallet->refresh();
        $this->assertSame(50_000, $this->wallet->balance->amount);
        $this->assertSame(50_000, $this->wallet->bonus->amount);
        $this->assertSame(0, $this->wallet->withdrawable()->amount);
        $this->assertSame(-50_000, $this->system(SystemAccount::Promotions)->balance->amount);

        try {
            app(RedeemBonusCoupon::class)->handle($this->buyer, 'WELCOME');
            $this->fail('A second redemption was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }

        $this->assertSame(1, Coupon::query()->sole()->used_count);
        $this->assertLedgerBalanced();
    }

    public function test_a_bonus_code_is_refused_when_used_up_expired_or_for_something_else(): void
    {
        Coupon::factory()->bonus()->create(['code' => 'USEDUP', 'max_uses' => 1, 'used_count' => 1]);
        Coupon::factory()->bonus()->create(['code' => 'OLD', 'ends_at' => now()->subMinute()]);
        Coupon::factory()->create(['code' => 'PLAN10']);

        foreach (['USEDUP', 'OLD', 'PLAN10', 'NOPE'] as $code) {
            try {
                app(RedeemBonusCoupon::class)->handle($this->buyer, $code);
                $this->fail("{$code} was accepted.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('code', $e->errors());
            }
        }

        $this->assertSame(0, $this->wallet->refresh()->bonus->amount);
    }

    public function test_a_purchase_spends_bonus_first_and_a_refund_gives_it_back_as_bonus(): void
    {
        $this->fund($this->wallet, 100_000);
        $this->bonus(30_000);

        $order = app(BuyNow::class)->handle($this->buyer, $this->auction(50_000));

        $this->wallet->refresh();
        $this->assertSame(30_000, $order->bonus_amount->amount);
        $this->assertSame(80_000, $this->wallet->balance->amount);
        $this->assertSame(0, $this->wallet->bonus->amount);

        $owner = User::query()->where('company_id', $order->company_id)->where('role', UserRole::CompanyOwner)->firstOrFail();
        app(CancelOrder::class)->handle($owner, $order, 'Out of stock');

        $this->wallet->refresh();
        $this->assertSame(130_000, $this->wallet->balance->amount);
        $this->assertSame(30_000, $this->wallet->bonus->amount);
        $this->assertSame(0, $order->refresh()->bonus_amount->amount);
        $this->assertLedgerBalanced();
    }

    public function test_bonus_credit_cannot_be_withdrawn(): void
    {
        $this->fund($this->wallet, 200_000);
        $this->bonus(500_000);
        $to = ['account_name' => 'Buyer', 'account_number' => '01711111111'];

        $this->assertSame(200_000, $this->wallet->refresh()->withdrawable()->amount);

        try {
            app(RequestWithdrawal::class)->handle($this->buyer, $this->wallet, Money::minor(300_000), PayoutMethod::Bkash, $to);
            $this->fail('Bonus credit was withdrawn.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('2,000.00', (string) collect($e->errors())->flatten()->first());
        }

        app(RequestWithdrawal::class)->handle($this->buyer, $this->wallet, Money::minor(200_000), PayoutMethod::Bkash, $to);
        $this->assertSame(0, $this->wallet->refresh()->withdrawable()->amount);
        $this->assertLedgerBalanced();
    }

    public function test_a_fine_takes_bonus_credit_first(): void
    {
        $this->fund($this->wallet, 20_000);
        $this->bonus(10_000);

        app(IssueFine::class)->handle(User::factory()->platformStaff()->create(), $this->wallet, Money::minor(15_000), 'Shill bidding');

        $this->wallet->refresh();
        $this->assertSame(15_000, $this->wallet->balance->amount);
        $this->assertSame(0, $this->wallet->bonus->amount);
        $this->assertLedgerBalanced();
    }

    public function test_the_verifier_catches_a_wrong_bonus_cache(): void
    {
        $this->bonus(10_000);
        $this->wallet->refresh()->forceFill(['bonus' => 5_000])->saveQuietly();

        $this->artisan('ledger:verify')->assertFailed();
    }
}
