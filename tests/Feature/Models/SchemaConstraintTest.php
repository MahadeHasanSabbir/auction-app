<?php

namespace Tests\Feature\Models;

use App\Enums\DisputeStatus;
use App\Enums\HoldStatus;
use App\Enums\HoldType;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rules the database itself enforces, whatever the application code does.
 */
class SchemaConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_auction_has_at_most_one_active_bid_hold(): void
    {
        $auction = Auction::factory()->live()->create();

        $this->hold(Wallet::factory()->create(), $auction);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->hold(Wallet::factory()->create(), $auction);
    }

    public function test_a_released_hold_makes_room_for_the_next_leader(): void
    {
        $auction = Auction::factory()->live()->create();

        $first = $this->hold(Wallet::factory()->create(), $auction);
        $first->update(['status' => HoldStatus::Released, 'released_at' => now()]);

        $second = $this->hold(Wallet::factory()->create(), $auction);

        $this->assertSame(1, WalletHold::query()->active()->where('auction_id', $auction->id)->count());
        $this->assertTrue($second->exists);
    }

    public function test_withdrawal_holds_are_not_limited_by_the_bid_hold_rule(): void
    {
        $wallet = Wallet::factory()->create();

        foreach ([1, 2] as $ignored) {
            WalletHold::query()->create([
                'wallet_id' => $wallet->id,
                'type' => HoldType::Withdrawal,
                'status' => HoldStatus::Active,
                'amount' => 10_000,
            ]);
        }

        $this->assertSame(2, $wallet->holds()->count());
    }

    public function test_bid_sequence_numbers_are_unique_within_an_auction(): void
    {
        $auction = Auction::factory()->live()->create();

        Bid::factory()->create(['auction_id' => $auction->id, 'seq' => 1]);
        Bid::factory()->create(['auction_id' => Auction::factory()->live(), 'seq' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);

        Bid::factory()->create(['auction_id' => $auction->id, 'seq' => 1]);
    }

    public function test_an_order_has_at_most_one_open_dispute(): void
    {
        $order = Order::factory()->create();

        $this->dispute($order);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->dispute($order);
    }

    public function test_a_new_dispute_can_be_opened_after_the_last_one_is_resolved(): void
    {
        $order = Order::factory()->create();

        $first = $this->dispute($order);
        $first->forceFill(['status' => DisputeStatus::Resolved, 'resolved_at' => now()])->save();

        $this->dispute($order);

        $this->assertSame(2, $order->disputes()->count());
    }

    public function test_an_auction_produces_at_most_one_order(): void
    {
        $order = Order::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Order::factory()->create(['auction_id' => $order->auction_id]);
    }

    public function test_a_company_has_one_subscription(): void
    {
        $subscription = Subscription::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Subscription::factory()->create(['company_id' => $subscription->company_id]);
    }

    public function test_a_buyer_has_one_wallet_and_watches_an_auction_once(): void
    {
        $buyer = User::factory()->create();
        $auction = Auction::factory()->live()->create();

        Wallet::factory()->create(['user_id' => $buyer->id]);
        $buyer->watchedAuctions()->attach($auction->id);

        try {
            $buyer->watchedAuctions()->attach($auction->id);
            $this->fail('A second watchlist row was accepted.');
        } catch (UniqueConstraintViolationException) {
            // expected
        }

        $this->expectException(UniqueConstraintViolationException::class);

        Wallet::factory()->create(['user_id' => $buyer->id]);
    }

    private function hold(Wallet $wallet, Auction $auction): WalletHold
    {
        return WalletHold::query()->create([
            'wallet_id' => $wallet->id,
            'type' => HoldType::Bid,
            'status' => HoldStatus::Active,
            'amount' => 100_000,
            'auction_id' => $auction->id,
        ]);
    }

    private function dispute(Order $order): Dispute
    {
        return Dispute::query()->forceCreate([
            'order_id' => $order->id,
            'opened_by' => $order->buyer_id,
            'reason' => 'Item not as described',
            'status' => DisputeStatus::Open,
        ]);
    }
}
