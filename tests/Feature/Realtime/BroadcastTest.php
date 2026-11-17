<?php

namespace Tests\Feature\Realtime;

use App\Actions\Auctions\CancelAuction;
use App\Actions\Auctions\CloseAuction;
use App\Actions\Auctions\StartAuction;
use App\Actions\Bidding\BuyNow;
use App\Actions\Bidding\PlaceBid;
use App\Enums\CommissionBasis;
use App\Events\Account\AuctionWon;
use App\Events\Account\Outbid;
use App\Events\Account\WalletUpdated;
use App\Events\Auctions\AuctionClosed;
use App\Events\Auctions\AuctionStarted;
use App\Events\Auctions\BidPlaced;
use App\Models\Auction;
use App\Models\Company;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fees\CommissionTerms;
use App\Services\Realtime\AuctionSnapshot;
use App\Services\Realtime\ViewerCount;
use App\Support\Money;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** What the auction engine broadcasts, to whom, and what it never reveals. */
class BroadcastTest extends TestCase
{
    use InteractsWithLedger;
    use RefreshDatabase;

    private Auction $auction;

    /** @var list<class-string> */
    private const EVENTS = [BidPlaced::class, AuctionStarted::class, AuctionClosed::class, Outbid::class, AuctionWon::class, WalletUpdated::class];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        $this->createPlatformAccounts();

        $terms = new CommissionTerms(1_000, Money::zero(), null, null, CommissionBasis::FinalPrice);
        $this->auction = Auction::factory()->live()->create([
            'starting_price' => 100_000,
            'min_increment' => 5_000,
            'reserve_price' => 400_000,
            'ends_at' => now()->addHour(),
            'fee_snapshot' => ['sale_commission' => $terms->toSnapshot()],
        ]);
    }

    private function buyer(int $units = 10_000): User
    {
        $buyer = User::factory()->create();
        $this->fund(Wallet::factory()->create(['user_id' => $buyer->id]), $units * 100);

        return $buyer;
    }

    private function bid(User $buyer, int $units, bool $auto = false): void
    {
        app(PlaceBid::class)->handle($buyer, $this->auction, Money::minor($units * 100), $auto);
    }

    public function test_a_bid_is_broadcast_on_the_auction_channel_and_the_old_leader_hears_they_were_outbid(): void
    {
        $first = $this->buyer();
        $second = $this->buyer();
        $this->bid($first, 1_000);

        Event::fake(self::EVENTS);

        $this->bid($second, 1_500);

        Event::assertDispatched(BidPlaced::class, fn (BidPlaced $event) => $event->auction->is($this->auction)
            && $event->broadcastOn()->name === 'auction.'.$this->auction->id
            && $event->broadcastAs() === 'bid.placed'
            && $event->broadcastQueue() === 'broadcasts');
        Event::assertDispatched(Outbid::class, function (Outbid $event) use ($first) {
            $channel = $event->broadcastOn();

            return $channel instanceof PrivateChannel && $channel->name === 'private-App.Models.User.'.$first->id;
        });
        Event::assertDispatchedTimes(Outbid::class, 1);
    }

    public function test_a_defended_bid_tells_nobody_they_were_outbid_and_a_hidden_raise_is_not_broadcast(): void
    {
        $leader = $this->buyer();
        $this->bid($leader, 3_000, auto: true);

        Event::fake(self::EVENTS);

        // The leader's maximum answers: the price moves, the leader stays.
        $this->bid($this->buyer(), 1_200);
        Event::assertDispatchedTimes(BidPlaced::class, 1);
        Event::assertNotDispatched(Outbid::class);

        // Raising one's own maximum (below the reserve) writes no bid: nothing public changed.
        $this->bid($leader, 3_500, auto: true);
        Event::assertDispatchedTimes(BidPlaced::class, 1);
    }

    public function test_the_snapshot_carries_no_user_id_maximum_or_reserve(): void
    {
        $leader = $this->buyer();
        $other = $this->buyer();
        $this->bid($leader, 3_000, auto: true);
        $this->bid($other, 1_200);

        $payload = (new BidPlaced($this->auction->refresh()))->broadcastWith();
        $json = (string) json_encode($payload);

        // The leader's maximum answered the 1,200.00 bid one increment above it.
        $this->assertSame(Money::minor(125_000)->format(), $payload['price']);
        $this->assertSame(3, $payload['bid_count']);
        $this->assertSame(6, $payload['seq']);
        $this->assertFalse($payload['reserve_met']);
        $this->assertSame(AuctionSnapshot::ref($this->auction->id, $leader->id), $payload['leader_ref']);
        $this->assertSame([3, 2, 1], array_column($payload['bids'], 'seq'));
        $this->assertSame($leader->maskedName(), $payload['bids'][0]['bidder']);

        $this->assertStringNotContainsString('3,000', $json, 'The leader\'s maximum leaked.');
        $this->assertStringNotContainsString('4,000', $json, 'The reserve leaked.');
        $this->assertStringNotContainsString($leader->name, $json);
        $this->assertArrayNotHasKey('user_id', $payload['bids'][0]);
        $this->assertNotSame(AuctionSnapshot::ref($this->auction->id, $leader->id), AuctionSnapshot::ref($this->auction->id + 1, $leader->id));
    }

    public function test_a_close_is_broadcast_and_the_winner_is_told(): void
    {
        $winner = $this->buyer();
        $this->bid($winner, 5_000);
        $this->travel(2)->hours();

        Event::fake(self::EVENTS);

        app(CloseAuction::class)->handle($this->auction);

        Event::assertDispatched(AuctionClosed::class, fn (AuctionClosed $event) => $event->broadcastWith()['final'] === true && $event->broadcastWith()['status'] === 'sold');
        Event::assertDispatched(AuctionWon::class, fn (AuctionWon $event) => $event->broadcastOn()->name === 'private-App.Models.User.'.$winner->id
            && str_contains($event->broadcastWith()['message'], $event->order->number));
    }

    public function test_an_unsold_close_tells_no_winner(): void
    {
        $this->bid($this->buyer(), 1_000);
        $this->travel(2)->hours();

        Event::fake(self::EVENTS);

        app(CloseAuction::class)->handle($this->auction);

        Event::assertDispatched(AuctionClosed::class);
        Event::assertNotDispatched(AuctionWon::class);
    }

    public function test_start_buy_now_and_cancel_are_broadcast(): void
    {
        Event::fake(self::EVENTS);

        $scheduled = Auction::factory()->scheduled()->create(['starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);
        app(StartAuction::class)->handle($scheduled);
        Event::assertDispatched(AuctionStarted::class, fn (AuctionStarted $event) => $event->auction->is($scheduled));

        $buyable = Auction::factory()->live()->create(['buy_now_price' => 50_000, 'ends_at' => now()->addHour(), 'fee_snapshot' => $this->auction->fee_snapshot]);
        app(BuyNow::class)->handle($this->buyer(), $buyable);
        Event::assertDispatched(AuctionClosed::class, fn (AuctionClosed $event) => $event->auction->is($buyable));

        app(CancelAuction::class)->handle(User::factory()->platformStaff()->create(), $this->auction, 'Breaks the rules.');
        Event::assertDispatched(AuctionClosed::class, fn (AuctionClosed $event) => $event->auction->is($this->auction));

        // A draft was never public: nobody is watching it.
        $draft = Auction::factory()->create();
        $owner = User::factory()->companyOwner(Company::query()->findOrFail($draft->company_id))->create();
        app(CancelAuction::class)->handle($owner, $draft);
        Event::assertNotDispatched(AuctionClosed::class, fn (AuctionClosed $event) => $event->auction->is($draft));
    }

    public function test_a_buyer_wallet_change_is_sent_to_its_owner_only(): void
    {
        Event::fake([WalletUpdated::class]);

        $buyer = $this->buyer(500);

        Event::assertDispatched(WalletUpdated::class, function (WalletUpdated $event) use ($buyer) {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()->name === 'private-App.Models.User.'.$buyer->id
                && $event->broadcastAs() === 'wallet.updated'
                && array_keys($payload) === ['available', 'balance', 'held', 'version'];
        });

        // Company and platform wallets have no user to tell.
        $company = Wallet::factory()->forCompany()->create();
        Event::fake([WalletUpdated::class]);
        $this->fund($company, 10_000);
        Event::assertNotDispatched(WalletUpdated::class);
    }

    public function test_nothing_is_broadcast_when_the_transaction_rolls_back(): void
    {
        $first = $this->buyer();
        $this->bid($first, 1_000);
        $second = $this->buyer();

        Event::fake(self::EVENTS);

        try {
            DB::transaction(function () use ($second): void {
                $this->bid($second, 1_500);

                throw new \RuntimeException('Rolled back.');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        Event::assertNotDispatched(BidPlaced::class);
        Event::assertNotDispatched(Outbid::class);
    }

    public function test_the_viewer_count_is_unknown_without_a_websocket_server(): void
    {
        $this->assertNull(app(ViewerCount::class)->of($this->auction->id));
        $this->assertSame('auction.5.viewers', ViewerCount::channel(5));
    }
}
