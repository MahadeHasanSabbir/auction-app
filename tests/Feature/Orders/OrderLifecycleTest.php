<?php

namespace Tests\Feature\Orders;

use App\Actions\Bidding\BuyNow;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\CompleteOrder;
use App\Actions\Orders\LeaveReview;
use App\Actions\Orders\OpenDispute;
use App\Actions\Orders\ReplyToReview;
use App\Actions\Orders\ResolveDispute;
use App\Actions\Orders\ShipOrder;
use App\Actions\Orders\UpdateDeliveryAddress;
use App\Actions\Orders\WithdrawDispute;
use App\Enums\CommissionBasis;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\SystemAccount;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fees\CommissionTerms;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** Shipping, delivery, auto-release, cancellation, disputes and reviews, with the money they move. */
class OrderLifecycleTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Wallet $companyWallet;

    private User $buyer;

    private Auction $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        $this->createPlatformAccounts();
        [$this->company, $this->owner, $this->companyWallet] = $this->company();

        $this->buyer = User::factory()->create(['address' => 'House 1, Road 2, Dhanmondi, Dhaka']);
        $this->fund(Wallet::factory()->create(['user_id' => $this->buyer->id]), 1_000_000);

        // 10% commission fixed at approval; the buyer buys now for 2,000.00.
        $terms = new CommissionTerms(1_000, Money::zero(), null, null, CommissionBasis::FinalPrice);
        $this->auction = Auction::factory()->live()->create([
            'company_id' => $this->company->id,
            'starting_price' => 100_000,
            'buy_now_price' => 200_000,
            'ends_at' => now()->addDay(),
            'fee_snapshot' => ['sale_commission' => $terms->toSnapshot()],
        ]);
    }

    private function order(): Order
    {
        return app(BuyNow::class)->handle($this->buyer, $this->auction);
    }

    private function ship(Order $order, ?User $by = null): Order
    {
        return app(ShipOrder::class)->handle($by ?? $this->owner, $order, ['courier' => 'Sundarban', 'tracking_number' => 'SB-1']);
    }

    private function balance(SystemAccount|Wallet|User $of): int
    {
        $wallet = match (true) {
            $of instanceof SystemAccount => $this->system($of),
            $of instanceof User => Wallet::query()->where('user_id', $of->id)->firstOrFail(),
            default => $of->refresh(),
        };

        return $wallet->balance->amount;
    }

    public function test_a_confirmed_delivery_pays_the_company_less_commission(): void
    {
        $order = $this->order();
        $this->assertSame('House 1, Road 2, Dhanmondi, Dhaka', $order->shipping_address);

        $member = User::factory()->companyMember($this->company)->create();
        $this->ship($order, $member);
        $this->assertSame(OrderStatus::Shipped, $order->status);
        $this->assertTrue($order->auto_release_at?->equalTo(now()->addDays((int) config('auction.orders.auto_release_days'))));

        app(CompleteOrder::class)->confirm($this->buyer, $order);

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->completed_at);
        $this->assertSame(ShipmentStatus::Delivered, $order->shipment?->status);
        $this->assertSame(20_000, $order->commission->amount);
        $this->assertSame(180_000, $order->seller_payout->amount);
        $this->assertSame(180_000, $this->balance($this->companyWallet));
        $this->assertSame(20_000, $this->balance(SystemAccount::CommissionRevenue));
        $this->assertSame(0, $this->balance(SystemAccount::Escrow));
        $this->assertSame(['created', 'shipped', 'completed'], $order->events()->pluck('event')->all());
        $this->assertLedgerBalanced();
    }

    public function test_the_ship_by_clock_waits_for_the_address(): void
    {
        $this->buyer->forceFill(['address' => null])->save();
        $order = $this->order();

        try {
            $this->ship($order);
            $this->fail('Shipped without an address.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('courier', $e->errors());
        }

        $this->travel(3)->days();
        app(UpdateDeliveryAddress::class)->handle($this->buyer, $order, [
            'shipping_name' => 'A Buyer',
            'shipping_phone' => '+880 1711 000000',
            'shipping_address' => 'Flat 4B, 12 Lake Road, Gulshan',
        ]);
        $this->assertTrue($order->ship_by_at?->equalTo(now()->addDays((int) config('auction.orders.ship_by_days'))));

        // A correction does not restart it.
        $this->travel(1)->day();
        app(UpdateDeliveryAddress::class)->handle($this->buyer, $order, [
            'shipping_name' => 'A Buyer',
            'shipping_phone' => '+880 1711 000000',
            'shipping_address' => 'Flat 5C, 12 Lake Road, Gulshan',
        ]);
        $this->assertTrue($order->ship_by_at->equalTo(now()->subDay()->addDays((int) config('auction.orders.ship_by_days'))));
        $this->assertSame('Flat 5C, 12 Lake Road, Gulshan', $order->refresh()->shipping_address);
    }

    public function test_the_sweep_releases_escrow_after_the_auto_release_date_only(): void
    {
        $order = $this->ship($this->order());

        $this->travelTo($order->auto_release_at->copy()->subSecond());
        $this->artisan('orders:sweep')->assertSuccessful();
        $this->assertSame(OrderStatus::Shipped, $order->refresh()->status);

        $this->travelTo($order->auto_release_at);
        $this->artisan('orders:sweep')->expectsOutputToContain('1 released')->assertSuccessful();
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        $this->assertSame(180_000, $this->balance($this->companyWallet));

        // A second sweep or a late confirmation does nothing.
        $this->assertFalse(app(CompleteOrder::class)->autoRelease($order));
        $this->assertLedgerBalanced();
    }

    public function test_an_unshipped_order_is_cancelled_and_refunded_after_the_ship_by_date(): void
    {
        $order = $this->order();
        $this->assertSame(1_000_000 - 200_000, $this->balance($this->buyer));
        $this->assertTrue($this->auction->product->isSold());

        $this->travelTo($order->ship_by_at);
        $this->artisan('orders:sweep')->expectsOutputToContain('1 cancelled')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('The seller did not ship in time.', $order->cancellation_reason);
        $this->assertSame(200_000, $order->refunded_amount->amount);
        $this->assertSame(1_000_000, $this->balance($this->buyer));
        $this->assertSame(0, $this->balance(SystemAccount::Escrow));
        $this->assertSame(0, $this->balance(SystemAccount::CommissionRevenue));
        // The product can be listed again.
        $this->assertFalse($this->auction->product->isSold());
        $this->assertLedgerBalanced();
    }

    public function test_only_the_owner_cancels_and_only_before_shipping(): void
    {
        $order = $this->order();
        $member = User::factory()->companyMember($this->company)->create();

        try {
            app(CancelOrder::class)->handle($member, $order, 'Out of stock');
            $this->fail('A member cancelled.');
        } catch (AuthorizationException) {
        }

        app(CancelOrder::class)->handle($this->owner, $order, 'The item broke in storage.');
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(1_000_000, $this->balance($this->buyer));

        $this->expectException(AuthorizationException::class);
        app(CancelOrder::class)->handle($this->owner, $order, 'Again');
    }

    public function test_others_cannot_act_on_an_order(): void
    {
        $order = $this->ship($this->order());
        $stranger = User::factory()->create();
        $otherSeller = User::factory()->companyOwner(Company::factory()->create())->create();
        $staff = User::factory()->platformStaff()->create();

        $this->assertFalse($stranger->can('view', $order));
        $this->assertFalse($otherSeller->can('view', $order));
        $this->assertTrue($staff->can('view', $order));
        $this->assertFalse($staff->can('confirm', $order));
        $this->assertFalse($this->owner->can('confirm', $order));

        $this->expectException(AuthorizationException::class);
        app(CompleteOrder::class)->confirm($stranger, $order);
    }

    public function test_a_dispute_freezes_release_until_withdrawn(): void
    {
        $order = $this->ship($this->order());

        app(OpenDispute::class)->handle($this->buyer, $order, [
            'reason' => 'not_as_described',
            'description' => 'The vase has a crack that was not in the pictures.',
        ]);
        $this->assertSame(OrderStatus::Disputed, $order->status);

        $this->travelTo($order->auto_release_at->copy()->addDay());
        $this->artisan('orders:sweep')->assertSuccessful();
        $this->assertSame(OrderStatus::Disputed, $order->refresh()->status);

        app(WithdrawDispute::class)->handle($this->buyer, $order);
        $this->assertSame(OrderStatus::Shipped, $order->status);
        $this->assertSame(DisputeStatus::Withdrawn, $order->disputes()->sole()->status);

        // Past the release date: no new dispute, and the next sweep releases it.
        $this->assertFalse($this->buyer->can('dispute', $order));
        $this->artisan('orders:sweep')->expectsOutputToContain('1 released');
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        $this->assertLedgerBalanced();
    }

    public function test_a_partial_refund_shrinks_the_commission_in_proportion(): void
    {
        $order = $this->ship($this->order());
        app(OpenDispute::class)->handle($this->buyer, $order, ['reason' => 'damaged', 'description' => 'One handle arrived broken off the cup.']);
        $staff = User::factory()->platformStaff()->create();

        try {
            app(ResolveDispute::class)->handle($staff, $order, DisputeResolution::RefundPartial, 'Too much', '2000');
            $this->fail('Refunded the whole price as a partial refund.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('refund', $e->errors());
        }

        $dispute = app(ResolveDispute::class)->handle($staff, $order, DisputeResolution::RefundPartial, 'Refund 30% for the damage.', '600');

        $order->refresh();
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->status);
        $this->assertSame(60_000, $order->refunded_amount->amount);
        // 70% kept: commission 20,000 × 0.7.
        $this->assertSame(14_000, $order->commission->amount);
        $this->assertSame(126_000, $order->seller_payout->amount);
        $this->assertSame(126_000, $this->balance($this->companyWallet));
        $this->assertSame(800_000 + 60_000, $this->balance($this->buyer));
        $this->assertSame(14_000, $this->balance(SystemAccount::CommissionRevenue));
        $this->assertSame(DisputeStatus::Resolved, $dispute->status);
        $this->assertSame(60_000, $dispute->refund_amount?->amount);
        $this->assertSame($staff->id, Activity::query()->where('log_name', 'money')->where('event', 'resolved')->sole()->causer_id);
        $this->assertLedgerBalanced();
    }

    public function test_staff_can_refund_in_full_or_release_to_the_seller(): void
    {
        $staff = User::factory()->platformStaff()->create();
        $order = $this->ship($this->order());
        app(OpenDispute::class)->handle($this->buyer, $order, ['reason' => 'not_received', 'description' => 'Nothing has arrived and tracking shows nothing.']);

        $this->expectsAuthorizationFailure(fn () => app(ResolveDispute::class)->handle($this->owner, $order, DisputeResolution::ReleaseToSeller, 'Mine'));

        app(ResolveDispute::class)->handle($staff, $order, DisputeResolution::RefundFull, 'The parcel was lost.');
        $this->assertSame(OrderStatus::Refunded, $order->refresh()->status);
        $this->assertSame(1_000_000, $this->balance($this->buyer));
        $this->assertSame(0, $this->balance($this->companyWallet));
        $this->assertSame(0, $order->commission->amount);
        $this->assertLedgerBalanced();
    }

    public function test_release_to_seller_completes_the_order(): void
    {
        $staff = User::factory()->platformStaff()->create();
        $order = $this->ship($this->order());
        app(OpenDispute::class)->handle($this->buyer, $order, ['reason' => 'other', 'description' => 'The colour is a bit different from the photos.']);

        app(ResolveDispute::class)->handle($staff, $order, DisputeResolution::ReleaseToSeller, 'Matches the description.');

        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        $this->assertSame(180_000, $this->balance($this->companyWallet));
        $this->assertTrue($this->buyer->can('review', $order));
        $this->assertLedgerBalanced();
    }

    public function test_a_buyer_reviews_a_completed_order_once_and_the_team_replies_once(): void
    {
        $order = $this->ship($this->order());
        $this->assertFalse($this->buyer->can('review', $order));
        app(CompleteOrder::class)->confirm($this->buyer, $order);

        $review = app(LeaveReview::class)->handle($this->buyer, $order, ['rating' => 4, 'comment' => 'Well packed.']);
        $this->company->refresh();
        $this->assertSame(4, $this->company->rating_sum);
        $this->assertSame(1, $this->company->rating_count);

        try {
            app(LeaveReview::class)->handle($this->buyer, $order, ['rating' => 5]);
            $this->fail('Reviewed twice.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rating', $e->errors());
        }

        $member = User::factory()->companyMember($this->company)->create();
        app(ReplyToReview::class)->handle($member, $review, 'Thank you!');
        $this->assertSame('Thank you!', $review->refresh()->reply);

        $this->expectException(ValidationException::class);
        app(ReplyToReview::class)->handle($this->owner, $review, 'Again');
    }

    public function test_reviews_close_after_the_review_window(): void
    {
        $order = $this->ship($this->order());
        app(CompleteOrder::class)->confirm($this->buyer, $order);

        $this->travel((int) config('auction.orders.review_days'))->days();

        $this->expectException(AuthorizationException::class);
        app(LeaveReview::class)->handle($this->buyer, $order, ['rating' => 5]);
    }

    /** @param  callable(): mixed  $callback */
    private function expectsAuthorizationFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the action to be refused.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
