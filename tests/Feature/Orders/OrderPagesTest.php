<?php

namespace Tests\Feature\Orders;

use App\Actions\Bidding\BuyNow;
use App\Actions\Orders\CompleteOrder;
use App\Actions\Orders\LeaveReview;
use App\Actions\Orders\ShipOrder;
use App\Enums\CommissionBasis;
use App\Enums\OrderStatus;
use App\Filament\Seller\Resources\Orders\Pages\ListOrders;
use App\Filament\Seller\Resources\Orders\Pages\ViewOrder;
use App\Livewire\Account\Orders;
use App\Livewire\Orders\Show;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fees\CommissionTerms;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** The buyer's order pages and the seller panel's order screens. */
class OrderPagesTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $buyer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        [$this->company, $this->owner] = $this->company($this->plan());

        $this->buyer = User::factory()->create(['address' => null]);
        $this->fund(Wallet::factory()->create(['user_id' => $this->buyer->id]), 1_000_000);

        $terms = new CommissionTerms(1_000, Money::zero(), null, null, CommissionBasis::FinalPrice);
        $auction = Auction::factory()->live()->create([
            'company_id' => $this->company->id,
            'title' => 'Brass oil lamp',
            'buy_now_price' => 200_000,
            'ends_at' => now()->addDay(),
            'fee_snapshot' => ['sale_commission' => $terms->toSnapshot()],
        ]);
        $this->order = app(BuyNow::class)->handle($this->buyer, $auction);
    }

    private function openSeller(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('seller');
        Filament::setTenant($this->company);
    }

    public function test_the_buyer_lists_and_opens_their_orders(): void
    {
        $this->actingAs($this->buyer);

        $this->get('/orders')->assertOk()->assertSee('Brass oil lamp')->assertSee($this->order->number);
        $this->get("/orders/{$this->order->number}")->assertOk()->assertSee('Add your delivery address');
        $this->get('/my-bids?tab=won')->assertOk()->assertSee("/orders/{$this->order->number}", false);

        Livewire::test(Orders::class, ['tab' => 'closed'])->assertDontSee('Brass oil lamp');
    }

    public function test_nobody_else_opens_the_buyer_page(): void
    {
        $this->actingAs(User::factory()->create())->get("/orders/{$this->order->number}")->assertNotFound();
        $this->actingAs($this->owner)->get("/orders/{$this->order->number}")->assertNotFound();
    }

    public function test_the_buyer_adds_an_address_confirms_and_reviews(): void
    {
        $this->actingAs($this->buyer);

        Livewire::test(Show::class, ['order' => $this->order])
            ->set('shipping_name', 'Rahim Uddin')
            ->set('shipping_phone', '01711-000000')
            ->set('shipping_address', 'short')
            ->call('saveAddress')
            ->assertHasErrors('shipping_address')
            ->set('shipping_address', 'House 9, Road 4, Banani, Dhaka')
            ->call('saveAddress')
            ->assertHasNoErrors()
            ->assertSet('editingAddress', false)
            ->assertSee('House 9, Road 4, Banani, Dhaka');

        app(ShipOrder::class)->handle($this->owner, $this->order, ['courier' => 'Pathao', 'tracking_number' => 'PT-77']);

        Livewire::test(Show::class, ['order' => $this->order])
            ->assertSee('Pathao')
            ->assertSee('PT-77')
            ->call('confirmDelivery')
            ->assertDispatched('toast');
        $this->assertSame(OrderStatus::Completed, $this->order->refresh()->status);

        Livewire::test(Show::class, ['order' => $this->order])
            ->set('rating', 5)
            ->set('comment', 'Exactly as pictured.')
            ->call('leaveReview')
            ->assertHasNoErrors()
            ->assertSee('Exactly as pictured.');
        $this->assertSame(5, $this->company->refresh()->rating_sum);
        $this->assertLedgerBalanced();
    }

    public function test_the_buyer_opens_and_withdraws_a_dispute(): void
    {
        $this->order->forceFill(['shipping_address' => 'House 9, Road 4, Banani, Dhaka'])->save();
        app(ShipOrder::class)->handle($this->owner, $this->order, ['courier' => 'Pathao']);
        $this->actingAs($this->buyer);

        Livewire::test(Show::class, ['order' => $this->order])
            ->set('disputing', true)
            ->set('reason', 'not_received')
            ->set('description', 'Too short')
            ->call('openDispute')
            ->assertHasErrors('description')
            ->set('description', 'Tracking has not moved for a week and nothing came.')
            ->call('openDispute')
            ->assertHasNoErrors()
            ->assertSee('The item has not arrived');
        $this->assertSame(OrderStatus::Disputed, $this->order->refresh()->status);

        Livewire::test(Show::class, ['order' => $this->order])->call('withdrawDispute');
        $this->assertSame(OrderStatus::Shipped, $this->order->refresh()->status);

        // A page left open after the order moved on is refused.
        $page = Livewire::test(Show::class, ['order' => $this->order]);
        app(CompleteOrder::class)->confirm($this->buyer, $this->order);
        $page->call('confirmDelivery')->assertForbidden();
    }

    public function test_the_team_sees_and_ships_orders_in_the_panel(): void
    {
        $member = User::factory()->companyMember($this->company)->create();
        $base = "/seller/{$this->company->slug}";
        $this->actingAs($member);

        $this->get("{$base}/orders")->assertOk()->assertSee($this->order->number);
        $this->get("{$base}/orders/{$this->order->number}")->assertOk()->assertSee('The buyer has not given an address yet.');

        // Another company's team cannot open it.
        [$other] = $this->company($this->plan());
        $this->actingAs(User::factory()->companyOwner($other)->create())
            ->get("/seller/{$other->slug}/orders/{$this->order->number}")->assertNotFound();

        $this->order->forceFill(['shipping_name' => 'Rahim', 'shipping_address' => 'House 9, Road 4, Banani, Dhaka'])->save();
        $this->openSeller($member);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$this->order])
            ->assertActionHidden(TestAction::make('cancel')->table($this->order))
            ->callAction(TestAction::make('ship')->table($this->order), ['courier' => 'Sundarban', 'tracking_number' => 'SB-9', 'tracking_url' => 'https://track.example/SB-9'])
            ->assertHasNoActionErrors();

        $this->assertSame(OrderStatus::Shipped, $this->order->refresh()->status);
        $this->assertSame('SB-9', $this->order->shipment?->tracking_number);
    }

    public function test_the_owner_cancels_and_the_team_replies_to_a_review(): void
    {
        $this->openSeller($this->owner);

        Livewire::test(ViewOrder::class, ['record' => $this->order->number])
            ->callAction('cancel', ['reason' => 'We found a crack while packing.'])
            ->assertHasNoActionErrors();
        $this->assertSame(OrderStatus::Cancelled, $this->order->refresh()->status);

        // A second order, completed and reviewed.
        $auction = Auction::factory()->live()->create([
            'company_id' => $this->company->id,
            'buy_now_price' => 50_000,
            'ends_at' => now()->addDay(),
            'fee_snapshot' => $this->order->auction->fee_snapshot,
        ]);
        $order = app(BuyNow::class)->handle($this->buyer, $auction);
        $order->forceFill(['shipping_address' => 'House 9, Road 4, Banani, Dhaka'])->save();
        app(ShipOrder::class)->handle($this->owner, $order, ['courier' => 'Pathao']);
        app(CompleteOrder::class)->confirm($this->buyer, $order);
        app(LeaveReview::class)->handle($this->buyer, $order, ['rating' => 3, 'comment' => 'Slow.']);

        $member = User::factory()->companyMember($this->company)->create();
        $this->openSeller($member);

        Livewire::test(ViewOrder::class, ['record' => $order->number])
            ->assertActionHidden('cancel')
            ->callAction('reply', ['reply' => 'Sorry for the delay.'])
            ->assertHasNoActionErrors()
            ->assertActionHidden('reply');
        $this->assertSame('Sorry for the delay.', $order->review?->refresh()->reply);
        $this->assertLedgerBalanced();
    }
}
