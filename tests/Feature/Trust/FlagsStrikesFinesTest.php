<?php

namespace Tests\Feature\Trust;

use App\Actions\Bidding\BuyNow;
use App\Actions\Bidding\PlaceBid;
use App\Actions\Orders\CancelOrder;
use App\Actions\Trust\IssueFine;
use App\Actions\Trust\IssueStrike;
use App\Actions\Trust\ReviewFraudFlag;
use App\Actions\Trust\RevokeStrike;
use App\Enums\CommissionBasis;
use App\Enums\FraudFlagStatus;
use App\Enums\InvoiceKind;
use App\Enums\StrikeSource;
use App\Enums\SystemAccount;
use App\Filament\Admin\Resources\Buyers\Pages\ViewBuyer;
use App\Filament\Admin\Resources\FraudFlags\Pages\ListFraudFlags;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\FraudFlag;
use App\Models\Invoice;
use App\Models\LoginEvent;
use App\Models\Strike;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fees\CommissionTerms;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** Shared-IP flags, strikes with the automatic suspension, and fines. */
class FlagsStrikesFinesTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        $this->createPlatformAccounts();
        $this->staff = User::factory()->platformStaff()->create();
    }

    private function buyer(int $units = 10_000): User
    {
        $buyer = User::factory()->create();
        $this->fund(Wallet::factory()->create(['user_id' => $buyer->id]), $units * 100);

        return $buyer;
    }

    /** The actions read the IP from the current request. */
    private function fromIp(string $ip): void
    {
        $this->app->instance('request', request()->duplicate(server: ['REMOTE_ADDR' => $ip]));
    }

    public function test_a_bid_from_an_ip_the_seller_team_signed_in_from_is_flagged(): void
    {
        [$company, $owner] = $this->company();
        $auction = Auction::factory()->live()->create(['company_id' => $company->id, 'starting_price' => 100_000, 'ends_at' => now()->addDay()]);
        LoginEvent::factory()->create(['user_id' => $owner->id, 'ip_address' => '203.0.113.9']);
        LoginEvent::factory()->failed()->create(['user_id' => $owner->id, 'ip_address' => '198.51.100.1']);

        $shill = $this->buyer();
        $honest = $this->buyer();

        $this->fromIp('198.51.100.1');
        app(PlaceBid::class)->handle($honest, $auction, Money::minor(100_000));
        $this->assertSame(0, FraudFlag::query()->count(), 'A failed sign-in does not count.');

        $this->fromIp('203.0.113.9');
        app(PlaceBid::class)->handle($shill, $auction, Money::minor(110_000));
        app(PlaceBid::class)->handle($shill, $auction, Money::minor(130_000), auto: true);

        $flag = FraudFlag::query()->sole();
        $this->assertSame($shill->id, $flag->user_id);
        $this->assertSame($company->id, $flag->company_id);
        $this->assertSame('203.0.113.9', $flag->ip_address);
        $this->assertSame([$owner->id], $flag->details['team_user_ids']);
        $this->assertSame('203.0.113.9', Bid::query()->where('user_id', $shill->id)->value('ip_address'));

        // Too long ago no longer counts.
        LoginEvent::query()->update(['created_at' => now()->subDays((int) config('auction.trust.shared_ip_days') + 1)]);
        $other = Auction::factory()->live()->create(['company_id' => $company->id, 'starting_price' => 100_000, 'ends_at' => now()->addDay()]);
        app(PlaceBid::class)->handle($shill, $other, Money::minor(100_000));
        $this->assertSame(1, FraudFlag::query()->count());
    }

    public function test_staff_confirm_a_flag_with_a_strike_or_dismiss_it(): void
    {
        $flag = FraudFlag::factory()->create();
        $other = FraudFlag::factory()->create();

        app(ReviewFraudFlag::class)->confirm($this->staff, $flag, 'Same household as the seller', strike: true);

        $this->assertSame(FraudFlagStatus::Confirmed, $flag->refresh()->status);
        $strike = Strike::query()->sole();
        $this->assertSame(StrikeSource::FraudFlag, $strike->source);
        $this->assertSame($flag->user_id, $strike->user_id);

        app(ReviewFraudFlag::class)->dismiss($this->staff, $other);
        $this->assertSame(FraudFlagStatus::Dismissed, $other->refresh()->status);

        $this->expectException(AuthorizationException::class);
        app(ReviewFraudFlag::class)->dismiss($this->staff, $other);
    }

    public function test_the_flags_desk_lists_open_flags_and_decides_them(): void
    {
        $flag = FraudFlag::factory()->create();
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->staff);

        Livewire::test(ListFraudFlags::class)
            ->assertCanSeeTableRecords([$flag])
            ->callTableAction('dismiss', $flag, ['note' => 'Office wifi'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Office wifi', $flag->refresh()->review_note);
        $this->actingAs($this->buyer())->get('/admin/flags')->assertForbidden();
    }

    public function test_the_strike_limit_suspends_and_a_revoked_strike_stops_counting(): void
    {
        $buyer = $this->buyer();
        $limit = (int) config('auction.trust.strike_limit');

        $first = app(IssueStrike::class)->handle($this->staff, $buyer, 'Abusive messages');
        app(RevokeStrike::class)->handle($this->staff, $first);
        $this->assertNotNull($first->refresh()->revoked_at);

        for ($i = 1; $i < $limit; $i++) {
            app(IssueStrike::class)->handle($this->staff, $buyer, "Strike {$i}");
        }
        $this->assertTrue($buyer->refresh()->is_active);

        // An expired strike does not count either.
        Strike::factory()->create(['user_id' => $buyer->id, 'expires_at' => now()->subDay()]);
        $this->assertTrue($buyer->refresh()->is_active);

        app(IssueStrike::class)->handle($this->staff, $buyer, 'One too many');
        $this->assertFalse($buyer->refresh()->is_active);
        $this->assertStringContainsString('One too many', (string) $buyer->suspension_reason);
    }

    public function test_only_buyers_get_strikes(): void
    {
        [, $owner] = $this->company();

        $this->assertNull(app(IssueStrike::class)->automatic($owner, StrikeSource::UnpaidOrder, 'n/a'));

        $this->expectException(AuthorizationException::class);
        app(IssueStrike::class)->handle($this->staff, $owner, 'Not a buyer');
    }

    public function test_a_buyer_who_gives_no_address_gets_a_strike_when_the_order_is_cancelled(): void
    {
        [$company] = $this->company();
        $buyer = $this->buyer();
        $auction = Auction::factory()->live()->create([
            'company_id' => $company->id,
            'starting_price' => 100_000,
            'buy_now_price' => 200_000,
            'ends_at' => now()->addDay(),
            'fee_snapshot' => ['sale_commission' => (new CommissionTerms(1_000, Money::zero(), null, null, CommissionBasis::FinalPrice))->toSnapshot()],
        ]);
        $order = app(BuyNow::class)->handle($buyer, $auction);
        $this->assertNull($order->shipping_address);

        $this->travelTo($order->ship_by_at->addMinute());
        $this->assertTrue(app(CancelOrder::class)->overdue($order));

        $strike = Strike::query()->sole();
        $this->assertSame(StrikeSource::UnpaidOrder, $strike->source);
        $this->assertSame($buyer->id, $strike->user_id);
        $this->assertSame($order->id, $strike->reference_id);
        $this->assertLedgerBalanced();
    }

    public function test_a_fine_moves_money_to_the_fines_account_with_a_notice(): void
    {
        $buyer = $this->buyer(1_000);
        $wallet = Wallet::query()->where('user_id', $buyer->id)->firstOrFail();

        $invoice = app(IssueFine::class)->handle($this->staff, $wallet, '250.00', 'Repeated non-payment');

        $this->assertSame(75_000, $wallet->refresh()->balance->amount);
        $this->assertSame(25_000, $this->system(SystemAccount::PenaltyRevenue)->balance->amount);
        $this->assertSame(InvoiceKind::Fine, $invoice->kind);
        $this->assertSame($buyer->id, $invoice->user_id);
        $this->assertSame(25_000, $invoice->total->amount);
        $this->assertMatchesRegularExpression('/^FIN-\d{4}-000001$/', $invoice->number);

        // A second fine gets the next number.
        $this->assertStringEndsWith('000002', app(IssueFine::class)->handle($this->staff, $wallet, '1.00', 'Again')->number);

        try {
            app(IssueFine::class)->handle($this->staff, $wallet, '5000.00', 'Too much');
            $this->fail('Fined more than the wallet holds.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertLedgerBalanced();
    }

    public function test_only_staff_fine_and_strike_from_the_buyer_page(): void
    {
        $buyer = $this->buyer(1_000);

        try {
            app(IssueFine::class)->handle($buyer, Wallet::query()->where('user_id', $buyer->id)->firstOrFail(), '1.00', 'Myself');
            $this->fail('A buyer fined a wallet.');
        } catch (AuthorizationException) {
        }

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->staff);

        Livewire::test(ViewBuyer::class, ['record' => $buyer->getRouteKey()])
            ->callAction('strike', ['reason' => 'Rude to a seller'])
            ->assertHasNoActionErrors()
            ->callAction('fine', ['amount' => '10.00', 'reason' => 'Late payment'])
            ->assertHasNoActionErrors();

        $this->assertSame(1, $buyer->strikes()->count());
        $this->assertSame(1, Invoice::query()->where('kind', InvoiceKind::Fine)->count());
        $this->assertLedgerBalanced();
    }
}
