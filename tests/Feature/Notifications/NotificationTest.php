<?php

namespace Tests\Feature\Notifications;

use App\Actions\Bidding\BuyNow;
use App\Actions\Notifications\UpdateNotificationPreferences;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\CompleteOrder;
use App\Actions\Orders\LeaveReview;
use App\Actions\Orders\ReplyToReview;
use App\Actions\Orders\ShipOrder;
use App\Enums\CommissionBasis;
use App\Enums\NotificationCategory;
use App\Enums\NotificationKind;
use App\Livewire\Account\Notifications;
use App\Livewire\Account\NotificationSettings;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\AppNotification;
use App\Services\Fees\CommissionTerms;
use App\Services\Notifications\Notifier;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** Who is told about what, through which channel, and the pages that show it. */
class NotificationTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $member;

    private User $buyer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        [$this->company, $this->owner] = $this->company($this->plan());
        $this->member = User::factory()->companyMember($this->company)->create();

        $this->buyer = User::factory()->create(['address' => 'House 1, Road 2, Dhanmondi, Dhaka']);
        $this->fund(Wallet::factory()->create(['user_id' => $this->buyer->id]), 1_000_000);
    }

    /** Opens an order by a buy-now purchase; notifications sent on the way are faked away. */
    private function openOrder(): Order
    {
        $terms = new CommissionTerms(1_000, Money::zero(), null, null, CommissionBasis::FinalPrice);
        $auction = Auction::factory()->live()->create([
            'company_id' => $this->company->id,
            'title' => 'Brass oil lamp',
            'buy_now_price' => 200_000,
            'ends_at' => now()->addDay(),
            'fee_snapshot' => ['sale_commission' => $terms->toSnapshot()],
        ]);

        return $this->order = app(BuyNow::class)->handle($this->buyer, $auction);
    }

    public function test_the_bell_is_on_for_everything_and_mail_only_for_the_important_kinds(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->wantsNotification(NotificationCategory::Bidding, 'bell'));
        $this->assertFalse($user->wantsNotification(NotificationCategory::Bidding, 'mail'));
        $this->assertTrue($user->wantsNotification(NotificationCategory::Orders, 'mail'));
        $this->assertFalse($user->wantsNotification(NotificationCategory::Activity, 'mail'));

        app(UpdateNotificationPreferences::class)->handle($user, [
            'orders' => ['bell' => false, 'mail' => false],
            'bidding' => ['bell' => true, 'mail' => true],
        ]);

        $user->refresh();
        $this->assertFalse($user->wantsNotification(NotificationCategory::Orders, 'bell'));
        $this->assertFalse($user->wantsNotification(NotificationCategory::Orders, 'mail'));
        $this->assertTrue($user->wantsNotification(NotificationCategory::Bidding, 'mail'));
        // A category that was left out is switched off, as a cleared box is.
        $this->assertFalse($user->wantsNotification(NotificationCategory::Support, 'mail'));
    }

    public function test_the_channels_follow_the_users_choices(): void
    {
        $user = User::factory()->create();
        $outbid = new AppNotification(NotificationKind::Outbid, 'Outbid', 'Body', url('/auctions/1'));
        $shipped = new AppNotification(NotificationKind::OrderShipped, 'Shipped', 'Body', url('/orders/ORD-1'));

        $this->assertSame(['database', 'broadcast'], $outbid->via($user));
        $this->assertSame(['database', 'broadcast', 'mail'], $shipped->via($user));

        app(UpdateNotificationPreferences::class)->handle($user, ['orders' => ['bell' => true, 'mail' => false]]);
        $this->assertSame(['database', 'broadcast'], $shipped->via($user->refresh()));

        $unverified = User::factory()->unverified()->create();
        $this->assertSame(['database', 'broadcast'], $shipped->via($unverified));

        // Only a path on this site is kept.
        $this->assertSame('/orders/ORD-1', $shipped->path);
    }

    public function test_the_notifier_skips_inactive_accounts_and_missing_people(): void
    {
        Notification::fake();
        $inactive = User::factory()->create(['is_active' => false]);

        app(Notifier::class)->send([$inactive, null, 999_999], NotificationKind::ChatMessage, 'Hi', 'Body', url('/'));

        Notification::assertNothingSent();
    }

    public function test_winning_tells_the_buyer_and_the_whole_team(): void
    {
        Notification::fake();

        $order = $this->openOrder();

        Notification::assertSentTo($this->buyer, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::AuctionWon && $n->path === "/orders/{$order->number}");
        Notification::assertSentTo([$this->owner, $this->member], AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::NewOrder);
    }

    public function test_shipping_and_delivery_tell_the_other_side_and_not_the_actor(): void
    {
        $order = $this->openOrder();
        Notification::fake();

        app(ShipOrder::class)->handle($this->member, $order, ['courier' => 'Pathao']);

        Notification::assertSentTo($this->buyer, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::OrderShipped);
        Notification::assertNotSentTo($this->member, AppNotification::class);

        app(CompleteOrder::class)->confirm($this->buyer, $order);

        Notification::assertSentTo([$this->owner, $this->member], AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::OrderCompleted);
        // The buyer confirmed it themselves, so they are not told about it again.
        Notification::assertNotSentTo($this->buyer, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::OrderCompleted);
    }

    public function test_an_automatic_release_tells_the_buyer_too(): void
    {
        $order = $this->openOrder();
        app(ShipOrder::class)->handle($this->owner, $order, ['courier' => 'Pathao']);
        Notification::fake();

        $this->travel(8)->days();
        $this->assertTrue(app(CompleteOrder::class)->autoRelease($order));

        Notification::assertSentTo($this->buyer, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::OrderCompleted);
    }

    public function test_cancelling_tells_the_buyer_and_the_rest_of_the_team(): void
    {
        $order = $this->openOrder();
        Notification::fake();

        app(CancelOrder::class)->handle($this->owner, $order, 'Out of stock after all');

        Notification::assertSentTo($this->buyer, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::OrderCancelled && str_contains($n->body, 'Out of stock'));
        Notification::assertSentTo($this->member, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::OrderCancelled);
        Notification::assertNotSentTo($this->owner, AppNotification::class);
    }

    public function test_a_review_and_its_reply_are_announced(): void
    {
        $order = $this->openOrder();
        app(ShipOrder::class)->handle($this->owner, $order, ['courier' => 'Pathao']);
        app(CompleteOrder::class)->confirm($this->buyer, $order);
        Notification::fake();

        $review = app(LeaveReview::class)->handle($this->buyer, $order, ['rating' => 5, 'comment' => 'Lovely lamp']);
        Notification::assertSentTo($this->owner, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::ReviewLeft);

        app(ReplyToReview::class)->handle($this->owner, $review, 'Thank you!');
        Notification::assertSentTo($this->buyer, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::ReviewReplied);
    }

    public function test_notifications_are_stored_for_the_bell_and_the_page_marks_them_read(): void
    {
        $this->openOrder();

        $this->assertSame(1, $this->buyer->unreadNotifications()->count());
        $stored = $this->buyer->notifications()->firstOrFail();
        $this->assertSame('auction_won', $stored->data['kind']);
        $this->assertSame('You won “'.Auction::query()->value('title').'”', $stored->data['title']);

        $this->actingAs($this->buyer)->get('/notifications')->assertOk()->assertSee($stored->data['title']);

        Livewire::test(Notifications::class)
            ->call('open', $stored->id)
            ->assertRedirect($stored->data['url']);

        $this->assertNotNull($stored->refresh()->read_at);
    }

    public function test_the_team_sees_its_alerts_in_the_seller_panels_bell(): void
    {
        $this->openOrder();

        $stored = $this->owner->notifications()->firstOrFail();

        // The same row feeds the site's bell and Filament's.
        $this->assertSame('new_order', $stored->data['kind']);
        $filament = FilamentNotification::fromDatabase($stored);
        $this->assertSame($stored->data['title'], $filament->getTitle());
        $this->assertSame($stored->data['url'], $filament->getActions()[0]->getUrl());

        $this->actingAs($this->owner);
        Filament::setCurrentPanel('seller');
        Filament::setTenant($this->company);

        $bell = Livewire::test(DatabaseNotifications::class)->instance();
        $this->assertSame(1, $bell->getUnreadNotificationsCount());
        $this->assertSame($stored->data['title'], $bell->getNotification($bell->getNotifications()->first())->getTitle());
    }

    public function test_one_cannot_open_or_clear_someone_elses_notifications(): void
    {
        $this->openOrder();
        $theirs = $this->buyer->notifications()->firstOrFail();
        $stranger = User::factory()->create();

        $this->actingAs($stranger);
        Livewire::test(Notifications::class)->call('open', $theirs->id)->assertNotFound();
        Livewire::test(Notifications::class)->call('markAllRead');

        $this->assertNull(DatabaseNotification::query()->findOrFail($theirs->id)->read_at);
    }

    public function test_mark_all_as_read_and_the_unread_filter(): void
    {
        $this->openOrder();
        $this->actingAs($this->buyer);

        Livewire::test(Notifications::class)->set('filter', 'unread')->assertSee('won')->call('markAllRead')->assertSee('Nothing unread');

        $this->assertSame(0, $this->buyer->unreadNotifications()->count());
    }

    public function test_the_settings_page_saves_the_switches(): void
    {
        $this->actingAs($this->buyer);

        $this->get('/profile/notifications')->assertOk()->assertSee('Messages and reviews');

        Livewire::test(NotificationSettings::class)
            ->assertSet('choices.orders.mail', true)
            ->assertSet('choices.bidding.mail', false)
            ->set('choices.orders.mail', false)
            ->set('choices.bidding.mail', true)
            ->call('save');

        $this->buyer->refresh();
        $this->assertFalse($this->buyer->wantsNotification(NotificationCategory::Orders, 'mail'));
        $this->assertTrue($this->buyer->wantsNotification(NotificationCategory::Bidding, 'mail'));
    }

    public function test_the_header_shows_the_unread_count_and_a_link_to_all(): void
    {
        $this->openOrder();

        $this->actingAs($this->buyer)->get('/auctions')->assertOk()
            ->assertSee('data-live="notification-count"', false)
            ->assertSee('See all notifications');
    }
}
