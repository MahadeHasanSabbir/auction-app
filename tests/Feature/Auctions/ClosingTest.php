<?php

namespace Tests\Feature\Auctions;

use App\Actions\Auctions\CloseAuction;
use App\Actions\Bidding\BuyNow;
use App\Actions\Bidding\PlaceBid;
use App\Enums\AuctionCloseReason;
use App\Enums\AuctionStatus;
use App\Enums\CommissionBasis;
use App\Enums\HoldStatus;
use App\Enums\OrderStatus;
use App\Enums\SystemAccount;
use App\Jobs\CloseAuctionJob;
use App\Models\Auction;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Services\Fees\CommissionTerms;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** Starting, closing and settling auctions, and buying now. */
class ClosingTest extends TestCase
{
    use InteractsWithLedger;
    use RefreshDatabase;

    private Auction $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        $this->createPlatformAccounts();

        // 10% commission fixed at approval.
        $terms = new CommissionTerms(1_000, Money::zero(), null, null, CommissionBasis::FinalPrice);
        $this->auction = Auction::factory()->live()->create([
            'starting_price' => 100_000,
            'min_increment' => 5_000,
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

    private function walletOf(User $user): Wallet
    {
        return Wallet::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function close(): bool
    {
        return app(CloseAuction::class)->handle($this->auction);
    }

    public function test_the_leader_wins_at_the_current_price_and_pays_into_escrow(): void
    {
        $winner = $this->buyer();
        $rival = $this->buyer();
        app(PlaceBid::class)->handle($winner, $this->auction, '3000', auto: true);
        app(PlaceBid::class)->handle($rival, $this->auction, '1500');

        $this->travelTo($this->auction->refresh()->ends_at);
        $this->assertTrue($this->close());

        $this->auction->refresh();
        $this->assertSame(AuctionStatus::Sold, $this->auction->status);
        $this->assertSame(AuctionCloseReason::Sold, $this->auction->close_reason);
        $this->assertSame($winner->id, $this->auction->winner_id);
        $this->assertSame(155_000, $this->auction->final_price?->amount);

        $order = Order::query()->where('auction_id', $this->auction->id)->firstOrFail();
        $this->assertSame(OrderStatus::InEscrow, $order->status);
        $this->assertSame(155_000, $order->amount->amount);
        $this->assertSame(15_500, $order->commission->amount);
        $this->assertSame(139_500, $order->seller_payout->amount);
        $this->assertSame('ORD-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT), $order->number);
        $this->assertTrue($order->ship_by_at?->equalTo(now()->addDays((int) config('auction.orders.ship_by_days'))));

        // The whole maximum was held; only the price left the wallet.
        $wallet = $this->walletOf($winner);
        $this->assertSame(1_000_000 - 155_000, $wallet->balance->amount);
        $this->assertTrue($wallet->held->isZero());
        $this->assertSame(155_000, $this->system(SystemAccount::Escrow)->balance->amount);
        $this->assertSame(0, WalletHold::query()->where('status', HoldStatus::Active)->count());
        $this->assertLedgerBalanced();
    }

    public function test_an_unmet_reserve_ends_unsold_and_releases_the_hold(): void
    {
        $this->auction->forceFill(['reserve_price' => 500_000])->save();
        $buyer = $this->buyer();
        app(PlaceBid::class)->handle($buyer, $this->auction, '1000');

        $this->travelTo($this->auction->ends_at);
        $this->close();

        $this->auction->refresh();
        $this->assertSame(AuctionStatus::Unsold, $this->auction->status);
        $this->assertSame(AuctionCloseReason::ReserveNotMet, $this->auction->close_reason);
        $this->assertTrue($this->walletOf($buyer)->held->isZero());
        $this->assertSame(0, Order::query()->count());
        $this->assertLedgerBalanced();
    }

    public function test_no_bids_ends_unsold(): void
    {
        $this->travelTo($this->auction->ends_at);
        $this->close();

        $this->assertSame(AuctionCloseReason::NoBids, $this->auction->refresh()->close_reason);
    }

    public function test_a_closer_that_comes_too_early_or_after_an_extension_does_nothing(): void
    {
        $this->assertFalse($this->close());

        // A last-second bid extends the end; the closer queued for the old end finds it running.
        $this->auction->forceFill(['ends_at' => now()->addSeconds(30)])->save();
        app(PlaceBid::class)->handle($this->buyer(), $this->auction, '1000');
        $this->travel(31)->seconds();

        $this->assertFalse($this->close());
        $this->assertSame(AuctionStatus::Live, $this->auction->refresh()->status);
    }

    public function test_closing_twice_makes_one_order(): void
    {
        app(PlaceBid::class)->handle($this->buyer(), $this->auction, '1000');
        $this->travelTo($this->auction->refresh()->ends_at);

        $this->assertTrue($this->close());
        $this->assertFalse($this->close());
        (new CloseAuctionJob($this->auction->id))->handle(app(CloseAuction::class));

        $this->assertSame(1, Order::query()->count());
        $this->assertLedgerBalanced();
    }

    public function test_the_sweep_starts_due_auctions_and_queues_ended_ones(): void
    {
        Queue::fake();
        $due = Auction::factory()->scheduled()->create(['starts_at' => now()->subSecond()]);
        $later = Auction::factory()->scheduled()->create(['starts_at' => now()->addMinute()]);
        $this->auction->forceFill(['ends_at' => now()->subSecond()])->save();

        $this->artisan('auctions:sweep')->assertSuccessful();

        $this->assertSame(AuctionStatus::Live, $due->refresh()->status);
        $this->assertSame(AuctionStatus::Scheduled, $later->refresh()->status);
        Queue::assertPushed(CloseAuctionJob::class, fn (CloseAuctionJob $job) => $job->auctionId === $this->auction->id);
        Queue::assertPushed(CloseAuctionJob::class, 1);
    }

    public function test_buy_now_sells_at_once_before_the_first_bid(): void
    {
        $this->auction->forceFill(['buy_now_price' => 500_000])->save();
        $buyer = $this->buyer();

        $order = app(BuyNow::class)->handle($buyer, $this->auction, 'buy-1');
        $again = app(BuyNow::class)->handle($buyer, $this->auction, 'buy-1');

        $this->assertSame($order->id, $again->id);
        $this->auction->refresh();
        $this->assertSame(AuctionStatus::Sold, $this->auction->status);
        $this->assertSame(AuctionCloseReason::BuyNow, $this->auction->close_reason);
        $this->assertSame(500_000, $order->amount->amount);
        $this->assertSame(1_000_000 - 500_000, $this->walletOf($buyer)->balance->amount);
        $this->assertSame(500_000, $this->system(SystemAccount::Escrow)->balance->amount);
        $this->assertLedgerBalanced();
    }

    public function test_buy_now_is_gone_after_the_first_bid_and_needs_the_full_price_available(): void
    {
        $this->auction->forceFill(['buy_now_price' => 500_000])->save();

        try {
            app(BuyNow::class)->handle($this->buyer(4_000), $this->auction);
            $this->fail('Bought without the money.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('buy_now', $e->errors());
        }

        app(PlaceBid::class)->handle($this->buyer(), $this->auction, '1000');

        $this->expectException(ValidationException::class);
        app(BuyNow::class)->handle($this->buyer(), $this->auction);
    }
}
