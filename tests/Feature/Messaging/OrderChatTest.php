<?php

namespace Tests\Feature\Messaging;

use App\Actions\Bidding\BuyNow;
use App\Actions\Messaging\PostOrderMessage;
use App\Enums\CommissionBasis;
use App\Enums\ConversationType;
use App\Enums\NotificationKind;
use App\Events\Messaging\MessagePosted;
use App\Filament\Seller\Resources\Orders\Pages\ViewOrder;
use App\Livewire\Messages\OrderChat;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\AppNotification;
use App\Services\Fees\CommissionTerms;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** The chat between a buyer and the selling team, on the order. */
class OrderChatTest extends TestCase
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

    private function say(User $who, string $text): Message
    {
        return app(PostOrderMessage::class)->handle($who, $this->order, $text);
    }

    public function test_the_chat_opens_with_the_order_and_both_sides_can_write(): void
    {
        $chat = Conversation::query()->where('order_id', $this->order->id)->firstOrFail();
        $this->assertSame(ConversationType::Order, $chat->type);
        $this->assertSame($this->company->id, $chat->company_id);

        $this->say($this->buyer, 'Can you ship to Mirpur?');
        $this->say($this->member, 'Yes, tomorrow.');

        $this->assertSame(['Can you ship to Mirpur?', 'Yes, tomorrow.'], $chat->messages()->pluck('body')->all());
        $this->assertNotNull($chat->refresh()->last_message_at);
        $this->assertSame(1, Conversation::query()->where('order_id', $this->order->id)->count());
    }

    public function test_only_the_buyer_and_the_selling_team_may_write(): void
    {
        $stranger = User::factory()->create();
        $otherSeller = $this->company($this->plan())[1];
        $staff = User::factory()->platformStaff()->create();

        foreach ([$stranger, $otherSeller, $staff] as $outsider) {
            try {
                $this->say($outsider, 'Hello');
                $this->fail('An outsider could write.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Message::query()->count());
    }

    public function test_the_text_is_checked(): void
    {
        foreach (['', '   ', str_repeat('x', 2001)] as $bad) {
            try {
                $this->say($this->buyer, $bad);
                $this->fail('Accepted a bad message.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('body', $e->errors());
            }
        }
    }

    public function test_flooding_is_stopped(): void
    {
        config(['auction.messaging.messages_per_minute' => 3]);

        foreach (range(1, 3) as $i) {
            $this->say($this->buyer, "Message {$i}");
        }

        $this->expectException(ValidationException::class);
        $this->say($this->buyer, 'One too many');
    }

    public function test_the_other_side_is_told_once_until_they_read(): void
    {
        Notification::fake();
        $conversation = Conversation::query()->where('order_id', $this->order->id)->firstOrFail();

        $this->say($this->buyer, 'First');
        $this->say($this->buyer, 'Second');

        // Both team members hear about the first message only.
        Notification::assertSentToTimes($this->owner, AppNotification::class, 1);
        Notification::assertSentToTimes($this->member, AppNotification::class, 1);
        Notification::assertSentTo($this->owner, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::ChatMessage);
        Notification::assertNotSentTo($this->buyer, AppNotification::class);

        // The owner answers (so has read) and the buyer is told; the owner is rung again by the next message, then not again until they read.
        $this->travel(1)->minutes();
        $this->say($this->owner, 'Hello!');
        Notification::assertSentToTimes($this->buyer, AppNotification::class, 1);

        $this->travel(1)->minutes();
        $this->say($this->buyer, 'Third');
        Notification::assertSentToTimes($this->owner, AppNotification::class, 2);

        $this->travel(1)->minutes();
        $conversation->participants()->updateExistingPivot($this->owner->id, ['last_read_at' => now()]);
        $this->travel(1)->minutes();
        $this->say($this->buyer, 'Fourth');
        Notification::assertSentToTimes($this->owner, AppNotification::class, 3);
    }

    public function test_a_message_tells_the_open_pages_to_reload(): void
    {
        Event::fake([MessagePosted::class]);

        $message = $this->say($this->buyer, 'Hello');

        Event::assertDispatched(MessagePosted::class, fn (MessagePosted $e) => $e->messageId === $message->id && $e->broadcastOn()->name === 'private-conversation.'.$message->conversation_id);
    }

    public function test_the_conversation_channel_is_for_its_participants_and_staff(): void
    {
        $chat = Conversation::query()->where('order_id', $this->order->id)->firstOrFail();

        $this->assertTrue(Gate::forUser($this->buyer)->allows('view', $chat));
        $this->assertTrue(Gate::forUser($this->member)->allows('view', $chat));
        $this->assertTrue(Gate::forUser(User::factory()->platformStaff()->create())->allows('view', $chat));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('view', $chat));
        $this->assertFalse(Gate::forUser($this->company($this->plan())[1])->allows('view', $chat));

        // Staff can read an order chat but not write in it.
        $this->assertFalse(Gate::forUser(User::factory()->platformStaff()->create())->allows('post', $chat));
    }

    public function test_the_buyer_chats_on_their_order_page(): void
    {
        $this->actingAs($this->buyer);

        $this->get("/orders/{$this->order->number}")->assertOk()->assertSee('Messages with');

        Livewire::test(OrderChat::class, ['orderId' => $this->order->id])
            ->assertSee('No messages yet')
            ->set('body', 'Is it still available in brass?')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('body', '')
            ->assertSee('Is it still available in brass?')
            ->set('body', '')
            ->call('send')
            ->assertHasErrors('body');
    }

    public function test_marking_read_records_the_reading_time(): void
    {
        $this->say($this->buyer, 'Hello');
        $this->actingAs($this->owner);

        Livewire::test(OrderChat::class, ['orderId' => $this->order->id])->call('markRead')->assertSee('Hello');

        $chat = Conversation::query()->where('order_id', $this->order->id)->firstOrFail();
        $this->assertNotNull($chat->participants()->whereKey($this->owner->id)->firstOrFail()->pivot->last_read_at);
    }

    public function test_a_stranger_cannot_open_the_chat_component(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(OrderChat::class, ['orderId' => $this->order->id])->assertForbidden();
    }

    public function test_the_seller_sees_the_chat_in_the_panel(): void
    {
        $this->say($this->buyer, 'Is it brass?');

        $this->actingAs($this->owner);
        Filament::setCurrentPanel('seller');
        Filament::setTenant($this->company);

        Livewire::test(ViewOrder::class, ['record' => $this->order->getRouteKey()])
            ->assertSee('Messages with the buyer')
            ->assertSee('Is it brass?');
    }
}
