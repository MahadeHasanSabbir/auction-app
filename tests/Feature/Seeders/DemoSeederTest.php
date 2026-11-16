<?php

namespace Tests\Feature\Seeders;

use App\Enums\AuctionStatus;
use App\Enums\HoldStatus;
use App\Enums\OrderStatus;
use App\Enums\SystemAccount;
use App\Enums\UserRole;
use App\Models\Auction;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use InteractsWithLedger;
    use RefreshDatabase;

    public function test_the_platform_seeder_creates_the_essentials_and_can_run_twice(): void
    {
        $this->seed(PlatformSeeder::class);
        $this->seed(PlatformSeeder::class);

        $owner = User::query()->where('role', UserRole::SystemOwner)->sole();

        $this->assertSame('admin@auction.com', $owner->email);
        $this->assertTrue(Hash::check('admin', $owner->password));
        $this->assertSame(count(SystemAccount::cases()), Wallet::query()->whereNotNull('system_account')->count());
        $this->assertDatabaseCount('plans', 3);
        $this->assertDatabaseCount('user_types', 4);
        $this->assertDatabaseCount('fee_rules', 6);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_the_demo_data_covers_every_auction_status(): void
    {
        $this->seed(DatabaseSeeder::class);

        $seen = Auction::query()->distinct()->pluck('status')->map->value->sort()->values()->all();
        $all = collect(AuctionStatus::cases())->map->value->sort()->values()->all();

        $this->assertSame($all, $seen);
        $this->assertSame(
            [OrderStatus::Completed->value, OrderStatus::InEscrow->value, OrderStatus::Shipped->value],
            Order::query()->distinct()->orderBy('status')->pluck('status')->map->value->all(),
        );
    }

    public function test_every_demo_team_fits_its_plan_and_every_account_has_the_right_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $companies = Company::query()->with(['subscription.plan', 'users', 'wallet'])->get();

        $this->assertCount(5, $companies);

        foreach ($companies as $company) {
            $this->assertLessThanOrEqual($company->subscription->maxUsers(), $company->users->count(), $company->name);
            $this->assertSame(1, $company->users->where('role', UserRole::CompanyOwner)->count(), $company->name);
            $this->assertNotNull($company->wallet, $company->name);
        }

        // Buyers never belong to a company, and only buyers bid.
        $this->assertSame(0, User::query()->where('role', UserRole::Buyer)->whereNotNull('company_id')->count());
        $this->assertSame(0, DB::table('bids')->join('users', 'users.id', '=', 'bids.user_id')->where('users.role', '!=', UserRole::Buyer->value)->count());
    }

    public function test_the_demo_ledger_balances(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Every transaction sums to zero.
        $unbalanced = LedgerEntry::query()
            ->selectRaw('ledger_transaction_id, sum(amount) as total')
            ->groupBy('ledger_transaction_id')
            ->havingRaw('sum(amount) <> 0')
            ->count();
        $this->assertSame(0, $unbalanced);

        // Cached balances and held amounts equal what the rows say.
        foreach (Wallet::query()->withSum('entries', 'amount')->get() as $wallet) {
            $this->assertSame((int) $wallet->entries_sum_amount, $wallet->balance->amount, "balance of wallet {$wallet->id}");

            $held = (int) $wallet->holds()->where('status', HoldStatus::Active)->sum('amount');
            $this->assertSame($held, $wallet->held->amount, "held of wallet {$wallet->id}");
            $this->assertFalse($wallet->system_account === null && $wallet->available()->isNegative(), "wallet {$wallet->id} is overdrawn");
        }

        // Escrow holds exactly the orders that are not finished.
        $inEscrow = (int) Order::query()->whereIn('status', [OrderStatus::InEscrow, OrderStatus::Shipped])->sum('amount');
        $this->assertSame($inEscrow, Wallet::forSystem(SystemAccount::Escrow)->balance->amount);

        // Commission earned equals the commission of the completed orders.
        $commission = (int) Order::query()->where('status', OrderStatus::Completed)->sum('commission');
        $this->assertSame($commission, Wallet::forSystem(SystemAccount::CommissionRevenue)->balance->amount);

        // And everything ledger:verify checks.
        $this->assertLedgerBalanced();
    }

    public function test_live_auctions_have_a_leader_with_a_hold_and_sold_ones_an_order(): void
    {
        $this->seed(DatabaseSeeder::class);

        $live = Auction::query()->where('status', AuctionStatus::Live)->where('bid_count', '>', 0)
            ->with(['holds' => fn ($query) => $query->where('status', HoldStatus::Active), 'holds.wallet'])
            ->withCount('bids')
            ->get();

        $this->assertNotEmpty($live);

        foreach ($live as $auction) {
            $this->assertSame($auction->bid_count, $auction->bids_count, $auction->title);
            $this->assertCount(1, $auction->holds, $auction->title);
            $this->assertSame($auction->leader_id, $auction->holds->first()->wallet->user_id, $auction->title);
            $this->assertTrue($auction->holds->first()->amount->greaterThanOrEqual($auction->current_price), $auction->title);
        }

        $sold = Auction::query()->where('status', AuctionStatus::Sold)->with('order')->get();

        foreach ($sold as $auction) {
            $this->assertNotNull($auction->order, $auction->title);
            $this->assertSame($auction->winner_id, $auction->order->buyer_id, $auction->title);
            $this->assertTrue($auction->final_price->equals($auction->order->amount), $auction->title);
            $this->assertTrue($auction->order->amount->equals($auction->order->seller_payout->plus($auction->order->commission)), $auction->title);
        }
    }

    public function test_seeding_again_does_not_duplicate_the_demo_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = [User::query()->count(), Auction::query()->count(), LedgerEntry::query()->count()];

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts, [User::query()->count(), Auction::query()->count(), LedgerEntry::query()->count()]);
    }
}
