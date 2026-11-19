<?php

namespace Tests\Feature\Models;

use App\Casts\MoneyCast;
use App\Enums\AuctionStatus;
use App\Enums\SystemAccount;
use App\Enums\UserRole;
use App\Enums\WalletType;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\Bid;
use App\Models\BlacklistEntry;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\CompanyInvitation;
use App\Models\FeeRule;
use App\Models\FraudFlag;
use App\Models\Invoice;
use App\Models\KycRequest;
use App\Models\LoginEvent;
use App\Models\Notice;
use App\Models\Order;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Review;
use App\Models\SocialAccount;
use App\Models\Strike;
use App\Models\Subscription;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserType;
use App\Models\Wallet;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FactoryTest extends TestCase
{
    use RefreshDatabase;

    /** @param  class-string<Model>  $model */
    #[DataProvider('models')]
    public function test_every_factory_creates_a_valid_row(string $model): void
    {
        $instance = $model::factory()->create();

        $this->assertTrue($instance->exists);
        $this->assertDatabaseCount($instance->getTable(), 1);
    }

    /** @return array<string, array{class-string<Model>}> */
    public static function models(): array
    {
        return collect([
            User::class, UserType::class, Plan::class, Company::class, Subscription::class,
            CompanyAgreement::class, CompanyInvitation::class, Category::class, Product::class,
            ProductImage::class, Auction::class, Bid::class, AutoBid::class, Wallet::class,
            FeeRule::class, Order::class, Review::class, Notice::class, Page::class, TrustedDevice::class, SocialAccount::class, KycRequest::class,
            LoginEvent::class, FraudFlag::class, Strike::class, BlacklistEntry::class, Invoice::class,
        ])->mapWithKeys(fn (string $model) => [class_basename($model) => [$model]])->all();
    }

    #[DataProvider('auctionStates')]
    public function test_the_auction_factory_covers_every_status(string $state, AuctionStatus $status): void
    {
        $factory = Auction::factory();
        $auction = ($state === 'draft' ? $factory : $factory->{$state}())->create();

        $this->assertSame($status, $auction->refresh()->status);
        $this->assertSame($status->isFinal(), $auction->closed_at !== null);
        $this->assertSame(in_array($status, [AuctionStatus::Scheduled, AuctionStatus::Live, AuctionStatus::Sold, AuctionStatus::Unsold], true), $auction->ends_at !== null);
    }

    /** @return array<string, array{string, AuctionStatus}> */
    public static function auctionStates(): array
    {
        return [
            'draft' => ['draft', AuctionStatus::Draft],
            'pending review' => ['pendingReview', AuctionStatus::PendingReview],
            'rejected' => ['rejected', AuctionStatus::Rejected],
            'scheduled' => ['scheduled', AuctionStatus::Scheduled],
            'live' => ['live', AuctionStatus::Live],
            'sold' => ['sold', AuctionStatus::Sold],
            'unsold' => ['unsold', AuctionStatus::Unsold],
            'cancelled' => ['cancelled', AuctionStatus::Cancelled],
        ];
    }

    public function test_an_auction_its_product_and_its_creator_share_one_company(): void
    {
        $auction = Auction::factory()->create()->load(['product', 'creator']);

        $this->assertSame($auction->company_id, $auction->product->company_id);
        $this->assertSame($auction->company_id, $auction->creator->company_id);
        $this->assertSame(UserRole::CompanyMember, $auction->creator->role);
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_a_new_auction_starts_at_its_starting_price(): void
    {
        $product = Product::factory()->create();

        $auction = new Auction([
            'product_id' => $product->id,
            'title' => 'Test lot',
            'starting_price' => 250_000,
            'min_increment' => 10_000,
        ]);
        $auction->company_id = $product->company_id;
        $auction->created_by = $product->created_by;
        $auction->save();

        $auction->refresh();

        $this->assertSame(AuctionStatus::Draft, $auction->status);
        $this->assertTrue($auction->current_price->equals(Money::minor(250_000)));
        $this->assertTrue($auction->minimumNextBid()->equals(Money::minor(250_000)));

        $auction->forceFill(['bid_count' => 1, 'current_price' => 260_000, 'leader_id' => User::factory()->create()->id])->save();

        $this->assertTrue($auction->minimumNextBid()->equals(Money::minor(270_000)));
    }

    public function test_a_sold_auction_and_its_order_agree(): void
    {
        $order = Order::factory()->create()->load('auction');

        $this->assertSame($order->auction->winner_id, $order->buyer_id);
        $this->assertSame($order->auction->company_id, $order->company_id);
        $this->assertTrue($order->amount->equals($order->auction->final_price));
    }

    public function test_wallet_factory_states(): void
    {
        $buyer = Wallet::factory()->create();
        $company = Wallet::factory()->forCompany()->create();
        $escrow = Wallet::factory()->system(SystemAccount::Escrow)->create();

        $this->assertSame(WalletType::Buyer, $buyer->type);
        $this->assertNotNull($buyer->user_id);
        $this->assertSame(WalletType::Company, $company->type);
        $this->assertNotNull($company->company_id);
        $this->assertTrue(Wallet::forSystem(SystemAccount::Escrow)->is($escrow));
        $this->assertTrue($buyer->refresh()->available()->isZero());
    }

    public function test_money_columns_are_read_as_money_and_refuse_floats(): void
    {
        $plan = Plan::factory()->create(['price_monthly' => 123_456, 'price_yearly' => null]);

        $this->assertInstanceOf(Money::class, $plan->price_monthly);
        $this->assertSame(123_456, $plan->price_monthly->amount);
        $this->assertNull($plan->price_yearly);

        $plan->price_monthly = Money::minor(200_000);
        $plan->save();

        $this->assertSame(200_000, (int) $plan->getRawOriginal('price_monthly'));
        $this->assertSame(MoneyCast::class, $plan->getCasts()['price_monthly']);

        $this->expectException(InvalidArgumentException::class);

        $plan->price_monthly = 1234.5;
    }

    public function test_subscription_limits_fall_back_to_the_plan(): void
    {
        $plan = Plan::factory()->seats(5)->create(['max_active_auctions' => 20]);

        $standard = Subscription::factory()->create(['plan_id' => $plan->id])->load('plan');
        $custom = Subscription::factory()->create([
            'plan_id' => $plan->id,
            'max_users_override' => 12,
            'max_active_auctions_override' => 100,
        ])->load('plan');

        $this->assertSame(5, $standard->maxUsers());
        $this->assertSame(20, $standard->maxActiveAuctions());
        $this->assertSame(12, $custom->maxUsers());
        $this->assertSame(100, $custom->maxActiveAuctions());
    }

    public function test_pending_invitations_exclude_accepted_revoked_and_expired_ones(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->companyOwner($company)->create();
        $base = ['company_id' => $company->id, 'invited_by' => $owner->id];

        $pending = CompanyInvitation::factory()->create($base);
        CompanyInvitation::factory()->accepted()->create($base);
        CompanyInvitation::factory()->revoked()->create($base);
        CompanyInvitation::factory()->expired()->create($base);

        $this->assertSame([$pending->id], $company->invitations()->pending()->pluck('id')->all());
        $this->assertTrue($pending->isPending());
    }

    public function test_agreements_and_fee_rules_know_when_they_are_in_force(): void
    {
        $current = CompanyAgreement::factory()->create();
        CompanyAgreement::factory()->create(['effective_from' => now()->addWeek()]);
        CompanyAgreement::factory()->create(['effective_from' => now()->subMonth(), 'effective_until' => now()->subDay()]);

        $this->assertSame([$current->id], CompanyAgreement::query()->effectiveAt()->pluck('id')->all());

        $active = FeeRule::factory()->create();
        FeeRule::factory()->create(['is_active' => false]);
        FeeRule::factory()->create(['effective_from' => now()->addDay()]);

        $this->assertSame([$active->id], FeeRule::query()->effectiveAt()->pluck('id')->all());
    }

    public function test_only_public_statuses_are_visible_to_the_public(): void
    {
        $visible = [
            Auction::factory()->scheduled()->create()->id,
            Auction::factory()->live()->create()->id,
            Auction::factory()->sold()->create()->id,
            Auction::factory()->unsold()->create()->id,
        ];

        Auction::factory()->create();
        Auction::factory()->pendingReview()->create();
        Auction::factory()->rejected()->create();
        Auction::factory()->cancelled()->create();

        $this->assertEqualsCanonicalizing($visible, Auction::query()->visibleToPublic()->pluck('id')->all());
    }

    public function test_categories_nest(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $this->assertTrue($child->parent()->first()->is($parent));
        $this->assertTrue($parent->children()->first()->is($child));
    }
}
