<?php

namespace Tests\Feature\Panels;

use App\Actions\Bidding\BuyNow;
use App\Actions\Orders\OpenDispute;
use App\Actions\Orders\ShipOrder;
use App\Actions\Support\OpenTicket;
use App\Actions\Wallet\RequestWithdrawal;
use App\Enums\CommissionBasis;
use App\Enums\ConversationStatus;
use App\Enums\NotificationKind;
use App\Enums\OrderStatus;
use App\Enums\PayoutMethod;
use App\Enums\WithdrawalStatus;
use App\Filament\Admin\Resources\Buyers\Pages\ListBuyers;
use App\Filament\Admin\Resources\Buyers\Pages\ViewBuyer;
use App\Filament\Admin\Resources\Buyers\RelationManagers\OrdersRelationManager;
use App\Filament\Admin\Resources\Orders\Pages\ViewOrder;
use App\Filament\Admin\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Admin\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Filament\Admin\Resources\Withdrawals\Pages\ViewWithdrawal;
use App\Livewire\Support\StaffThread;
use App\Models\Auction;
use App\Models\Deposit;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Notifications\AppNotification;
use App\Services\Fees\CommissionTerms;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** The admin panel's work desks: withdrawals, deposits, orders and disputes, tickets, buyers. */
class AdminDeskTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private User $buyer;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        $this->owner = User::factory()->systemOwner()->create();
        $this->staff = User::factory()->platformStaff()->create();
        $this->buyer = User::factory()->create(['address' => 'House 1, Road 2, Dhanmondi, Dhaka']);
        $this->wallet = $this->fund(Wallet::factory()->create(['user_id' => $this->buyer->id]), 1_000_000);
        config(['auction.payments.withdrawal_min' => 50_000]);
    }

    private function openAdmin(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }

    private function withdrawal(): WithdrawalRequest
    {
        return app(RequestWithdrawal::class)->handle($this->buyer, $this->wallet, Money::minor(200_000), PayoutMethod::Bkash, [
            'account_name' => 'Rahim Uddin',
            'account_number' => '01712345678',
        ]);
    }

    private function disputedOrder(): Order
    {
        [$company] = $this->company($this->plan());
        $terms = new CommissionTerms(1_000, Money::zero(), null, null, CommissionBasis::FinalPrice);
        $auction = Auction::factory()->live()->create([
            'company_id' => $company->id,
            'buy_now_price' => 200_000,
            'ends_at' => now()->addDay(),
            'fee_snapshot' => ['sale_commission' => $terms->toSnapshot()],
        ]);
        $order = app(BuyNow::class)->handle($this->buyer, $auction);
        $member = User::query()->where('company_id', $company->id)->firstOrFail();
        app(ShipOrder::class)->handle($member, $order, ['courier' => 'Pathao']);
        app(OpenDispute::class)->handle($this->buyer, $order, ['reason' => 'damaged', 'description' => 'The glass arrived cracked in two places.']);

        return $order->refresh();
    }

    public function test_staff_open_the_desks_and_not_the_owners_pages(): void
    {
        $this->withdrawal();
        $order = $this->disputedOrder();
        $deposit = (new Deposit)->forceFill([
            'user_id' => $this->buyer->id, 'wallet_id' => $this->wallet->id, 'gateway' => 'fake', 'tran_id' => 'DEP-TEST-1',
            'amount' => 100_000, 'net_amount' => 100_000, 'currency' => 'BDT', 'status' => 'pending',
        ]);
        $deposit->save();
        $ticket = app(OpenTicket::class)->handle($this->buyer, ['subject' => 'Lost parcel', 'body' => 'My parcel has not arrived yet, please help.']);

        $this->actingAs($this->staff);
        foreach (['/admin/withdrawals', '/admin/deposits', "/admin/deposits/{$deposit->id}", '/admin/orders', "/admin/orders/{$order->number}",
            '/admin/disputes', '/admin/tickets', "/admin/tickets/{$ticket->id}", '/admin/buyers', "/admin/buyers/{$this->buyer->id}",
            '/admin/categories', '/admin/fee-rules'] as $page) {
            $this->get($page)->assertOk();
        }

        foreach (['/admin/staff', '/admin/user-types', '/admin/settings', '/admin/activity'] as $page) {
            $this->get($page)->assertForbidden();
        }

        $this->actingAs($this->owner);
        foreach (['/admin/staff', '/admin/user-types', '/admin/settings', '/admin/activity', '/admin/withdrawals'] as $page) {
            $this->get($page)->assertOk();
        }

        $this->actingAs($this->buyer)->get('/admin/withdrawals')->assertForbidden();
    }

    public function test_a_withdrawal_is_approved_and_paid_from_the_desk(): void
    {
        $withdrawal = $this->withdrawal();
        $this->openAdmin($this->staff);

        $this->get("/admin/withdrawals/{$withdrawal->id}")->assertOk()->assertSee('01712345678');

        Livewire::test(ListWithdrawals::class)
            ->assertCanSeeTableRecords([$withdrawal])
            ->callAction(TestAction::make('approve')->table($withdrawal));
        $this->assertSame(WithdrawalStatus::Approved, $withdrawal->refresh()->status);

        Livewire::test(ViewWithdrawal::class, ['record' => $withdrawal->id])
            ->assertActionHidden('approve')
            ->callAction('pay', ['reference' => 'BKS-123']);

        $withdrawal->refresh();
        $this->assertSame(WithdrawalStatus::Paid, $withdrawal->status);
        $this->assertSame('BKS-123', $withdrawal->payout_reference);
        $this->assertLedgerBalanced();
    }

    public function test_a_rejection_needs_a_reason_and_releases_the_hold(): void
    {
        $withdrawal = $this->withdrawal();
        $this->openAdmin($this->staff);

        Livewire::test(ViewWithdrawal::class, ['record' => $withdrawal->id])
            ->callAction('reject', ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        Livewire::test(ViewWithdrawal::class, ['record' => $withdrawal->id])
            ->callAction('reject', ['reason' => 'The account name does not match.']);

        $this->assertSame(WithdrawalStatus::Rejected, $withdrawal->refresh()->status);
        $this->assertSame(1_000_000, $this->wallet->refresh()->available()->amount);
    }

    public function test_a_dispute_is_decided_with_a_partial_refund(): void
    {
        $order = $this->disputedOrder();
        $this->openAdmin($this->staff);

        Livewire::test(ViewOrder::class, ['record' => $order->number])
            ->assertActionVisible('resolve')
            ->callAction('resolve', ['resolution' => 'refund_partial', 'refund' => '', 'note' => 'Split the cost.'])
            ->assertHasActionErrors(['refund' => 'required']);

        Livewire::test(ViewOrder::class, ['record' => $order->number])
            ->callAction('resolve', ['resolution' => 'refund_partial', 'refund' => '500', 'note' => 'One part was damaged; half refunded.']);

        $order->refresh();
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->status);
        $this->assertSame(50_000, $order->refunded_amount->amount);
        $this->assertLedgerBalanced();
    }

    public function test_staff_read_the_order_chat_but_cannot_write_in_it(): void
    {
        $order = $this->disputedOrder();
        $conversation = $order->conversation()->firstOrFail();
        DB::table('messages')->insert(['conversation_id' => $conversation->id, 'user_id' => $this->buyer->id, 'body' => 'Is it well packed?', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->staff)->get("/admin/orders/{$order->number}")
            ->assertOk()
            ->assertSee('Is it well packed?')
            ->assertDontSee('Write a message');
    }

    public function test_a_new_ticket_reaches_staff_and_is_answered_from_the_desk(): void
    {
        Notification::fake();

        $ticket = app(OpenTicket::class)->handle($this->buyer, ['subject' => 'Lost parcel', 'body' => 'My parcel has not arrived yet, please help.']);

        Notification::assertSentTo([$this->staff, $this->owner], AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::NewTicket && $n->path === "/admin/tickets/{$ticket->id}");
        Notification::assertNotSentTo($this->buyer, AppNotification::class);

        $this->openAdmin($this->staff);
        Livewire::test(ViewTicket::class, ['record' => $ticket->id])->callAction('take');
        $this->assertSame($this->staff->id, $ticket->refresh()->assigned_to);

        Livewire::test(StaffThread::class, ['ticketId' => $ticket->id])
            ->set('body', 'We have asked the courier; you will hear from us today.')
            ->call('reply')
            ->assertHasNoErrors()
            ->assertSee('We have asked the courier');

        Notification::assertSentTo($this->buyer, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::TicketReply);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])->callAction('close');
        $this->assertSame(ConversationStatus::Closed, $ticket->refresh()->status);
    }

    public function test_handing_a_ticket_over_tells_the_colleague(): void
    {
        $ticket = app(OpenTicket::class)->handle($this->buyer, ['subject' => 'Lost parcel', 'body' => 'My parcel has not arrived yet, please help.']);
        $colleague = User::factory()->platformStaff()->create();
        Notification::fake();

        $this->openAdmin($this->staff);
        Livewire::test(ViewTicket::class, ['record' => $ticket->id])->callAction('assign', ['assignee' => $colleague->id]);

        $this->assertSame($colleague->id, $ticket->refresh()->assigned_to);
        Notification::assertSentTo($colleague, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::NewTicket);
    }

    public function test_staff_suspend_and_reactivate_a_buyer(): void
    {
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $this->buyer->id, 'payload' => '', 'last_activity' => time()]);
        $this->openAdmin($this->staff);

        Livewire::test(ListBuyers::class)
            ->assertCanSeeTableRecords([$this->buyer])
            ->assertCanNotSeeTableRecords([$this->staff])
            ->callAction(TestAction::make('suspend')->table($this->buyer), ['reason' => 'Bidding with no intention to pay.']);

        $this->buyer->refresh();
        $this->assertFalse($this->buyer->is_active);
        $this->assertSame('Bidding with no intention to pay.', $this->buyer->suspension_reason);
        $this->assertDatabaseMissing('sessions', ['user_id' => $this->buyer->id]);

        // A suspended account fails sign-in like a wrong password.
        $this->post('/logout');
        $this->post('/login', ['email' => $this->buyer->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->openAdmin($this->staff);
        Livewire::test(ViewBuyer::class, ['record' => $this->buyer->id])->callAction('reactivate');
        $this->assertTrue($this->buyer->refresh()->is_active);
        $this->assertNull($this->buyer->suspension_reason);
    }

    public function test_nobody_suspends_themselves_a_colleague_or_a_seller_from_the_buyer_list(): void
    {
        [, $seller] = $this->company($this->plan());

        $this->assertFalse($this->staff->can('suspend', $this->staff));
        $this->assertFalse($this->staff->can('suspend', User::factory()->platformStaff()->create()));
        $this->assertFalse($this->staff->can('suspend', $this->owner));
        $this->assertFalse($this->staff->can('suspend', $seller));
        $this->assertTrue($this->owner->can('suspend', $this->staff));

        $this->actingAs($this->staff)->get("/admin/buyers/{$seller->id}")->assertNotFound();
    }

    public function test_the_buyer_page_lists_their_orders(): void
    {
        $order = $this->disputedOrder();

        $this->actingAs($this->staff)->get("/admin/buyers/{$this->buyer->id}")
            ->assertOk()
            ->assertSee($this->buyer->email);

        Filament::setCurrentPanel('admin');
        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $this->buyer, 'pageClass' => ViewBuyer::class])
            ->assertCanSeeTableRecords([$order])
            ->assertSee($order->number);
    }
}
