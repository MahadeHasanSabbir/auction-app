<?php

namespace Tests\Feature\Engagement;

use App\Actions\Bidding\BuyNow;
use App\Actions\Orders\CompleteOrder;
use App\Actions\Orders\ShipOrder;
use App\Actions\Referrals\RewardReferral;
use App\Enums\CommissionBasis;
use App\Enums\OrderStatus;
use App\Enums\SystemAccount;
use App\Livewire\Account\Referrals;
use App\Models\Auction;
use App\Models\Order;
use App\Models\User;
use App\Models\UserType;
use App\Models\Wallet;
use App\Services\Fees\CommissionTerms;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** Referral links, and the bonus credit paid when the new buyer's first order completes. */
class ReferralTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->createPlatformAccounts();
        config(['auction.engagement.referral_bonus_referrer' => 20_000, 'auction.engagement.referral_bonus_referred' => 10_000]);
    }

    public function test_every_buyer_gets_a_code_and_a_sign_up_through_it_is_remembered(): void
    {
        UserType::factory()->create(['is_default' => true]);
        $referrer = User::factory()->create();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', (string) $referrer->referral_code);

        $this->get('/register?ref='.strtolower((string) $referrer->referral_code))->assertOk();
        $this->post('/register', [
            'account_type' => 'buyer',
            'name' => 'Friend',
            'email' => 'friend@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertSame($referrer->id, User::query()->where('email', 'friend@example.com')->sole()->referred_by_id);
    }

    public function test_the_first_completed_order_pays_both_buyers_once(): void
    {
        [$company, $owner] = $this->company();
        $referrer = User::factory()->create();
        $referrerWallet = Wallet::factory()->create(['user_id' => $referrer->id]);
        $buyer = User::factory()->create(['referred_by_id' => $referrer->id, 'address' => 'House 1, Dhaka']);
        $buyerWallet = $this->fund(Wallet::factory()->create(['user_id' => $buyer->id]), 500_000);

        $terms = new CommissionTerms(1_000, Money::zero(), null, null, CommissionBasis::FinalPrice);
        $auction = Auction::factory()->live()->create([
            'company_id' => $company->id,
            'starting_price' => 10_000,
            'buy_now_price' => 100_000,
            'ends_at' => now()->addDay(),
            'fee_snapshot' => ['sale_commission' => $terms->toSnapshot()],
        ]);

        $order = app(BuyNow::class)->handle($buyer, $auction);
        $this->assertFalse(app(RewardReferral::class)->handle($buyer->id), 'Paid before the order completed.');

        app(ShipOrder::class)->handle($owner, $order, ['courier' => 'Sundarban', 'tracking_number' => 'SB-1']);
        app(CompleteOrder::class)->confirm($buyer, $order); // the queued job runs at once (sync)

        $this->assertSame(10_000, $buyerWallet->refresh()->bonus->amount);
        $this->assertSame(20_000, $referrerWallet->refresh()->bonus->amount);
        $this->assertSame(-30_000, $this->system(SystemAccount::Promotions)->balance->amount);
        $this->assertNotNull($buyer->refresh()->referral_rewarded_at);

        $this->assertFalse(app(RewardReferral::class)->handle($buyer->id), 'Paid twice.');
        $this->assertLedgerBalanced();
    }

    public function test_a_suspended_referrer_gets_nothing_but_the_new_buyer_does(): void
    {
        $referrer = User::factory()->create(['is_active' => false]);
        Wallet::factory()->create(['user_id' => $referrer->id]);
        $buyer = User::factory()->create(['referred_by_id' => $referrer->id]);
        $wallet = Wallet::factory()->create(['user_id' => $buyer->id]);
        Order::factory()->create(['buyer_id' => $buyer->id, 'status' => OrderStatus::Completed]);

        $this->assertTrue(app(RewardReferral::class)->handle($buyer->id));

        $this->assertSame(10_000, $wallet->refresh()->bonus->amount);
        $this->assertSame(-10_000, $this->system(SystemAccount::Promotions)->balance->amount);
        $this->assertLedgerBalanced();
    }

    public function test_the_referrals_page_shows_the_link_and_counts(): void
    {
        $buyer = User::factory()->create();
        User::factory()->create(['referred_by_id' => $buyer->id, 'referral_rewarded_at' => now()]);
        User::factory()->create(['referred_by_id' => $buyer->id]);

        $this->actingAs($buyer)->get(route('referrals'))->assertOk()->assertSee((string) $buyer->referralUrl(), false);

        Livewire::actingAs($buyer)->test(Referrals::class)->assertViewHas('joined', 2)->assertViewHas('rewarded', 1);
    }
}
