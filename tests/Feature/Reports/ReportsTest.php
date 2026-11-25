<?php

namespace Tests\Feature\Reports;

use App\Enums\AuctionCloseReason;
use App\Enums\LedgerComponent;
use App\Enums\LedgerTransactionType;
use App\Enums\OrderStatus;
use App\Enums\SystemAccount;
use App\Filament\Admin\Pages\Reports\AuctionResults;
use App\Filament\Admin\Pages\Reports\MoneyFlow;
use App\Filament\Admin\Pages\Reports\Revenue;
use App\Filament\Admin\Pages\Reports\Sales;
use App\Filament\Admin\Widgets\SalesChart;
use App\Filament\Admin\Widgets\SalesOverview;
use App\Filament\Seller\Pages\Reports\Sales as SellerSales;
use App\Filament\Seller\Widgets\SalesChart as SellerSalesChart;
use App\Filament\Seller\Widgets\SalesOverview as SellerSalesOverview;
use App\Livewire\Account\Purchases;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger\Ledger;
use App\Support\Csv;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** Report pages in both panels, the dashboards and the buyer's purchase report. */
class ReportsTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private User $buyer;

    private Company $company;

    private User $seller;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        $this->owner = User::factory()->systemOwner()->create();
        $this->staff = User::factory()->platformStaff()->create();
        $this->buyer = User::factory()->create(['name' => '=Formula Buyer']);
        [$this->company, $this->seller] = $this->company($this->plan());
        [$this->other] = $this->company($this->plan());
    }

    private function sale(Company $company, int $amount, int $daysAgo, array $attributes = []): Order
    {
        $auction = Auction::factory()->sold()->create([
            'company_id' => $company->id,
            'winner_id' => $this->buyer->id,
            'final_price' => $amount,
            'current_price' => $amount,
            'bid_count' => 3,
            'closed_at' => now()->subDays($daysAgo),
        ]);

        return Order::factory()->create($attributes + [
            'auction_id' => $auction->id,
            'amount' => $amount,
            'seller_payout' => $amount,
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    private function openAdmin(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }

    /** The CSV a Livewire call downloaded, as rows (byte-order mark dropped). */
    private function csv(Testable $component): array
    {
        $content = base64_decode((string) data_get($component->effects, 'download.content'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        return array_map(fn (string $line) => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", substr($content, 3)))));
    }

    public function test_the_sales_report_covers_the_last_30_days_and_downloads_as_csv(): void
    {
        $recent = $this->sale($this->company, 100_000, 5, ['commission' => 5_000, 'seller_payout' => 95_000]);
        $refunded = $this->sale($this->other, 200_000, 10, ['status' => OrderStatus::PartiallyRefunded, 'refunded_amount' => 50_000]);
        $old = $this->sale($this->company, 300_000, 60);

        $this->openAdmin($this->staff);

        $page = Livewire::test(Sales::class)
            ->assertCanSeeTableRecords([$recent, $refunded])
            ->assertCanNotSeeTableRecords([$old])
            ->assertSee(Money::minor(300_000)->format())
            ->assertSee(Money::minor(50_000)->format())
            ->callAction('export');

        $rows = $this->csv($page);
        $this->assertCount(3, $rows);
        $this->assertSame([$refunded->number, $recent->number], [$rows[2][1], $rows[1][1]]);
        $this->assertSame("'=Formula Buyer", $rows[1][4], 'A cell that looks like a formula is defused.');
        $this->assertSame('1000.00', $rows[1][6]);
        $this->assertSame('50.00', $rows[1][8]);
        $page->assertFileDownloaded(Csv::filename('sales', now()->subDays(29)->setTimezone(config('auction.display_timezone'))->toDateString(), null));

        Livewire::test(Sales::class)
            ->filterTable('company_id', $this->company->id)
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$refunded])
            ->filterTable('period', ['from' => now()->subDays(90)->toDateString(), 'until' => null])
            ->assertCanSeeTableRecords([$recent, $old]);
    }

    public function test_a_seller_sees_only_its_own_sales(): void
    {
        $mine = $this->sale($this->company, 100_000, 2);
        $theirs = $this->sale($this->other, 200_000, 2);

        $this->actingAs($this->seller);
        Filament::setCurrentPanel('seller');
        Filament::setTenant($this->company);

        $page = Livewire::test(SellerSales::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->callAction('export');

        $rows = $this->csv($page);
        $this->assertCount(2, $rows);
        $this->assertNotContains(__('Seller'), $rows[0]);

        $this->get('/seller/'.$this->company->slug.'/reports/sales')->assertOk();
        $this->get('/seller/'.$this->company->slug.'/reports/auctions')->assertOk();
        $this->get('/seller/'.$this->company->slug)->assertOk();
        Livewire::test(SellerSalesOverview::class)->assertSee(__('Net sales, 30 days'))->assertSee(Money::minor(100_000)->format());
        Livewire::test(SellerSalesChart::class)->assertOk();
    }

    public function test_auction_results_count_sold_and_unsold(): void
    {
        $sold = $this->sale($this->company, 100_000, 3)->auction;
        $unsold = Auction::factory()->unsold()->create(['company_id' => $this->company->id]);
        $cancelled = Auction::factory()->cancelled()->create(['company_id' => $this->company->id]);

        $this->openAdmin($this->staff);

        Livewire::test(AuctionResults::class)
            ->assertCanSeeTableRecords([$sold, $unsold])
            ->assertCanNotSeeTableRecords([$cancelled])
            ->assertSee('1 (50%)')
            ->filterTable('close_reason', [AuctionCloseReason::NoBids->value])
            ->assertCanSeeTableRecords([$unsold])
            ->assertCanNotSeeTableRecords([$sold]);
    }

    public function test_revenue_is_read_from_the_ledger_and_is_for_the_owner_only(): void
    {
        $ledger = app(Ledger::class);
        $ledger->transaction(LedgerTransactionType::EscrowRelease)
            ->debit($ledger->system(SystemAccount::Escrow), Money::minor(7_500))
            ->credit($ledger->system(SystemAccount::CommissionRevenue), Money::minor(7_500), LedgerComponent::Commission)
            ->post();

        $this->openAdmin($this->staff);
        $this->get('/admin/reports/revenue')->assertForbidden();
        $this->get('/admin/reports/sales')->assertOk();
        Livewire::test(SalesOverview::class)->assertDontSee(__('Commission, 30 days'));

        $this->openAdmin($this->owner);
        Livewire::test(Revenue::class)
            ->assertCountTableRecords(1)
            ->assertSee(Money::minor(7_500)->format());

        Livewire::test(SalesOverview::class)->assertSee(__('Commission, 30 days'));
    }

    public function test_money_in_and_out_follows_the_clearing_accounts(): void
    {
        $wallet = $this->fund(Wallet::factory()->create(['user_id' => $this->buyer->id]), 250_000);

        $ledger = app(Ledger::class);
        $ledger->transaction(LedgerTransactionType::Withdrawal)
            ->debit($wallet, Money::minor(100_000))
            ->credit($ledger->system(SystemAccount::PayoutClearing), Money::minor(100_000))
            ->post();

        $this->openAdmin($this->staff);

        $page = Livewire::test(MoneyFlow::class)
            ->assertCountTableRecords(2)
            ->assertSee(Money::minor(250_000)->format())
            ->assertSee(Money::minor(150_000)->format())
            ->callAction('export');

        $rows = $this->csv($page);
        $this->assertSame(['', '1000.00'], [$rows[1][3], $rows[1][4]]);
        $this->assertSame(['2500.00', ''], [$rows[2][3], $rows[2][4]]);
    }

    public function test_a_buyer_sees_and_downloads_their_purchases(): void
    {
        $recent = $this->sale($this->company, 100_000, 3, ['status' => OrderStatus::PartiallyRefunded, 'refunded_amount' => 40_000]);
        $earlier = $this->sale($this->other, 50_000, 40);
        $someoneElse = Order::factory()->create();

        $this->actingAs($this->buyer)->get('/purchases')->assertOk()->assertSee($recent->number)->assertDontSee($someoneElse->number);

        $page = Livewire::test(Purchases::class)
            ->assertSee(Money::minor(150_000)->format())
            ->assertSee(Money::minor(110_000)->format())
            ->set('from', CarbonImmutable::now(config('auction.display_timezone'))->subDays(10)->toDateString())
            ->assertSee($recent->number)
            ->assertDontSee($earlier->number)
            ->set('from', 'not a date')
            ->assertSet('from', '')
            ->assertSee($earlier->number)
            ->call('export')
            ->assertFileDownloaded('purchases.csv');

        $rows = $this->csv($page);
        $this->assertCount(3, $rows);
        $this->assertSame([$earlier->number, '500.00'], [$rows[1][1], $rows[1][8]]);
        $this->assertSame([$recent->number, '600.00'], [$rows[2][1], $rows[2][8]]);

        $this->actingAs($this->seller)->get('/purchases')->assertRedirect();
    }

    public function test_the_admin_dashboard_shows_the_desks(): void
    {
        $this->sale($this->company, 100_000, 1);
        Auction::factory()->live()->create(['company_id' => $this->company->id]);

        $this->openAdmin($this->staff);

        $this->get('/admin')->assertOk();

        Livewire::test(SalesOverview::class)
            ->assertSee(__('Net sales, 30 days'))
            ->assertSee(Money::minor(100_000)->format())
            ->assertSee(__('Tickets not taken'));
        Livewire::test(SalesChart::class)->assertOk();
    }
}
