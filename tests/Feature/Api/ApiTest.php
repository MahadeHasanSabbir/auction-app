<?php

namespace Tests\Feature\Api;

use App\Actions\Bidding\BuyNow;
use App\Enums\CommissionBasis;
use App\Enums\NotificationKind;
use App\Enums\OrderStatus;
use App\Models\Auction;
use App\Models\Category;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\AppNotification;
use App\Services\Fees\CommissionTerms;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** The v1 REST API: public reads, token rules, buyer and seller endpoints. */
class ApiTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $buyer;

    private Auction $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        [$this->company, $this->owner] = $this->company($this->plan());

        $this->buyer = User::factory()->create();
        $this->fund(Wallet::factory()->create(['user_id' => $this->buyer->id]), 1_000_000);

        $this->auction = Auction::factory()->live()->create([
            'company_id' => $this->company->id,
            'title' => 'Brass oil lamp',
            'starting_price' => 100_000,
            'min_increment' => 5_000,
            'reserve_price' => 150_000,
            'buy_now_price' => 400_000,
            'ends_at' => now()->addDay(),
            'fee_snapshot' => ['sale_commission' => (new CommissionTerms(1_000, Money::zero(), null, null, CommissionBasis::FinalPrice))->toSnapshot()],
        ]);
    }

    public function test_the_public_list_and_detail_never_show_the_reserve(): void
    {
        $this->getJson('/api/v1/auctions')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Brass oil lamp')
            ->assertJsonPath('data.0.current_price.amount', 100_000)
            ->assertJsonPath('data.0.reserve_met', false)
            ->assertJsonMissingPath('data.0.reserve_price');

        $response = $this->getJson("/api/v1/auctions/{$this->auction->id}")->assertOk();
        $this->assertStringNotContainsString('150000', json_encode($response->json()));
        $response->assertJsonPath('data.next_minimum_bid.amount', 100_000)->assertJsonPath('data.is_leading', false);
    }

    public function test_the_list_filters_and_clamps_page_size(): void
    {
        Auction::factory()->live()->create(['title' => 'Silver spoon', 'ends_at' => now()->addDay()]);

        $this->getJson('/api/v1/auctions?q=spoon')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Silver spoon');
        $this->getJson('/api/v1/auctions?per_page=1000')->assertOk()->assertJsonPath('meta.per_page', 50);
    }

    public function test_drafts_do_not_exist_for_the_public(): void
    {
        $draft = Auction::factory()->create();

        $this->getJson("/api/v1/auctions/{$draft->id}")->assertNotFound()->assertJsonPath('message', 'Not found.');
        $this->getJson('/api/v1/auctions/999999')->assertNotFound();
    }

    public function test_categories_and_seller_pages(): void
    {
        Category::factory()->create(['name' => 'Antiques']);

        $this->getJson('/api/v1/categories')->assertOk()->assertJsonFragment(['name' => 'Antiques']);
        $this->getJson("/api/v1/sellers/{$this->company->slug}")->assertOk()->assertJsonPath('data.name', $this->company->name)->assertJsonMissingPath('data.email');
    }

    public function test_the_bid_history_masks_bidders(): void
    {
        Sanctum::actingAs($this->buyer, ['read', 'write']);
        $this->postJson("/api/v1/auctions/{$this->auction->id}/bids", ['amount' => '1000.00'])->assertOk();

        $this->getJson("/api/v1/auctions/{$this->auction->id}/bids")
            ->assertOk()
            ->assertJsonPath('data.0.amount.amount', 100_000)
            ->assertJsonPath('data.0.bidder', $this->buyer->maskedName());
    }

    public function test_protected_endpoints_need_a_token(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->postJson("/api/v1/auctions/{$this->auction->id}/bids", ['amount' => '1000'])->assertUnauthorized();
    }

    public function test_a_real_token_signs_in_and_can_be_revoked(): void
    {
        $token = $this->buyer->createToken('phone', ['read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $this->buyer->email)
            ->assertJsonPath('data.token.name', 'phone')
            ->assertJsonMissingPath('data.password');

        $this->withToken($token)->deleteJson('/api/v1/me/token')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_read_only_token_cannot_change_anything(): void
    {
        Sanctum::actingAs($this->buyer, ['read']);

        $this->getJson('/api/v1/wallet')->assertOk();
        $this->postJson("/api/v1/auctions/{$this->auction->id}/bids", ['amount' => '1000.00'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This token is read-only.');
        $this->assertSame(0, $this->auction->refresh()->bid_count);
    }

    public function test_staff_and_suspended_accounts_cannot_use_the_api(): void
    {
        Sanctum::actingAs(User::factory()->platformStaff()->create(), ['read', 'write']);
        $this->getJson('/api/v1/me')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['is_active' => false]), ['read', 'write']);
        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_a_buyer_bids_and_a_repeated_key_gives_the_first_answer(): void
    {
        Sanctum::actingAs($this->buyer, ['read', 'write']);

        $first = $this->postJson("/api/v1/auctions/{$this->auction->id}/bids", ['amount' => '1000.00'], ['Idempotency-Key' => 'abc-1'])
            ->assertOk()
            ->assertJsonPath('data.leading', true)
            ->assertJsonPath('data.price.amount', 100_000);

        $this->postJson("/api/v1/auctions/{$this->auction->id}/bids", ['amount' => '1000.00'], ['Idempotency-Key' => 'abc-1'])
            ->assertOk()
            ->assertJsonPath('data.price.amount', $first->json('data.price.amount'));

        $this->assertSame(1, $this->auction->refresh()->bid_count);
        $this->getJson('/api/v1/my/auctions')->assertOk()->assertJsonPath('data.0.my_top_bid.amount', 100_000)->assertJsonPath('data.0.is_leading', true);
        $this->assertLedgerBalanced();
    }

    public function test_bid_rules_are_the_same_as_on_the_website(): void
    {
        Sanctum::actingAs($this->buyer, ['read', 'write']);

        $this->postJson("/api/v1/auctions/{$this->auction->id}/bids", ['amount' => '1.00'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/auctions/{$this->auction->id}/bids", ['amount' => 'lots'])->assertUnprocessable()->assertJsonValidationErrors('amount');

        Sanctum::actingAs($this->owner, ['read', 'write']);
        $this->postJson("/api/v1/auctions/{$this->auction->id}/bids", ['amount' => '1000.00'])->assertForbidden();
    }

    public function test_the_wallet_and_statement(): void
    {
        Sanctum::actingAs($this->buyer, ['read']);

        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.balance.amount', 1_000_000)->assertJsonPath('data.available.amount', 1_000_000);
        $this->getJson('/api/v1/wallet/statement')->assertOk()->assertJsonPath('data.0.amount.amount', 1_000_000);
    }

    public function test_buy_now_ship_confirm_and_the_order_chat(): void
    {
        Sanctum::actingAs($this->buyer, ['read', 'write']);

        $number = $this->postJson("/api/v1/auctions/{$this->auction->id}/buy-now")
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::InEscrow->value)
            ->assertJsonMissingPath('data.commission')
            ->json('data.number');

        $this->patchJson("/api/v1/orders/{$number}/delivery-address", [
            'shipping_name' => 'Rahim Uddin',
            'shipping_phone' => '01711-000000',
            'shipping_address' => 'House 9, Road 4, Banani, Dhaka',
        ])->assertOk()->assertJsonPath('data.delivery.name', 'Rahim Uddin');

        $this->postJson("/api/v1/orders/{$number}/messages", ['body' => 'Please wrap it well.'])->assertCreated()->assertJsonPath('data.mine', true);

        // The selling team ships through its own endpoint and sees its money.
        Sanctum::actingAs($this->owner, ['read', 'write']);
        $this->postJson("/api/v1/seller/orders/{$number}/ship", ['courier' => 'Pathao', 'tracking_number' => 'PT-77'])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Shipped->value)
            ->assertJsonPath('data.shipment.courier', 'Pathao')
            ->assertJsonStructure(['data' => ['commission', 'seller_payout']]);
        $this->getJson('/api/v1/seller/orders?status=shipped')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/orders/{$number}/messages")->assertOk()->assertJsonPath('data.0.body', 'Please wrap it well.')->assertJsonPath('data.0.mine', false);

        Sanctum::actingAs($this->buyer, ['read', 'write']);
        $this->postJson("/api/v1/orders/{$number}/confirm")->assertOk()->assertJsonPath('data.status', OrderStatus::Completed->value);
        $this->postJson("/api/v1/orders/{$number}/review", ['rating' => 5, 'comment' => 'Lovely.'])->assertOk()->assertJsonPath('data.review.rating', 5);
        $this->getJson('/api/v1/orders?tab=closed')->assertOk()->assertJsonCount(1, 'data');
        $this->assertLedgerBalanced();
    }

    public function test_orders_are_private(): void
    {
        $order = app(BuyNow::class)->handle($this->buyer, $this->auction);

        Sanctum::actingAs(User::factory()->create(), ['read', 'write']);
        $this->getJson("/api/v1/orders/{$order->number}")->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->number}/confirm")->assertForbidden();

        // Another company's team cannot see or ship it either.
        [, $other] = $this->company($this->plan());
        Sanctum::actingAs($other, ['read', 'write']);
        $this->getJson("/api/v1/orders/{$order->number}")->assertForbidden();
        $this->postJson("/api/v1/seller/orders/{$order->number}/ship", ['courier' => 'X'])->assertNotFound();
        $this->getJson('/api/v1/seller/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_seller_keeps_products_with_pictures_and_drafts_an_auction(): void
    {
        Storage::fake('public');
        $category = Category::factory()->create();

        Sanctum::actingAs($this->owner, ['read', 'write']);

        $id = $this->post('/api/v1/seller/products', [
            'name' => 'Brass table clock',
            'category_id' => $category->id,
            'description' => 'Working order.',
            'images' => [UploadedFile::fake()->image('clock.jpg', 800, 600)],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(1, 'data.images')
            ->json('data.id');

        $this->getJson('/api/v1/seller/products')->assertOk()->assertJsonFragment(['name' => 'Brass table clock']);

        $this->postJson('/api/v1/seller/auctions', [
            'product_id' => $id,
            'title' => 'Brass table clock, 1950s',
            'starting_price' => '1000',
            'min_increment' => '50',
            'proposed_starts_at' => now()->addDay()->toIso8601ZuluString(),
            'proposed_ends_at' => now()->addDays(4)->toIso8601ZuluString(),
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.starting_price.amount', 100_000);

        $this->postJson('/api/v1/seller/auctions', ['product_id' => $id, 'title' => 'x'])->assertUnprocessable();
    }

    public function test_a_seller_cannot_touch_another_companys_catalogue_and_buyers_cannot_use_seller_endpoints(): void
    {
        $product = Product::factory()->create();

        Sanctum::actingAs($this->owner, ['read', 'write']);
        $this->getJson("/api/v1/seller/products/{$product->id}")->assertNotFound();
        $this->deleteJson("/api/v1/seller/products/{$product->id}")->assertNotFound();

        Sanctum::actingAs($this->buyer, ['read', 'write']);
        $this->getJson('/api/v1/seller/products')->assertForbidden();
        $this->getJson('/api/v1/seller/auctions')->assertForbidden();
    }

    public function test_notifications_list_and_mark_read(): void
    {
        $this->buyer->notify(new AppNotification(NotificationKind::Outbid, 'You were outbid', 'Someone bid more.', '/auctions/1'));
        Sanctum::actingAs($this->buyer, ['read', 'write']);

        $id = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.title', 'You were outbid')
            ->json('data.0.id');

        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/v1/notifications?unread=1')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.unread', 0);

        // Someone else's notification does not exist for this account.
        Sanctum::actingAs(User::factory()->create(), ['read', 'write']);
        $this->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
    }

    public function test_the_api_is_rate_limited(): void
    {
        config(['auction.api.guest_per_minute' => 2]);

        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson('/api/v1/categories')->assertStatus(429);
    }

    public function test_orders_of_a_buyer_are_listed_newest_first(): void
    {
        $order = app(BuyNow::class)->handle($this->buyer, $this->auction);

        Sanctum::actingAs($this->buyer, ['read']);
        $this->getJson('/api/v1/orders?tab=open')->assertOk()->assertJsonPath('data.0.number', $order->number);
        $this->assertSame(1, Order::query()->count());
    }
}
