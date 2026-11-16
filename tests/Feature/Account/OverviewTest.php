<?php

namespace Tests\Feature\Account;

use App\Enums\OrderStatus;
use App\Livewire\Account\Overview;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The buyer's starting page.
 */
class OverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_buyer_sees_the_wallet_and_how_much_can_be_bid(): void
    {
        config(['auction.bidding.biddable_share_bps' => 8000]);

        $buyer = User::factory()->create(['name' => 'Rahim Uddin']);
        Wallet::factory()->create(['user_id' => $buyer->id, 'balance' => 1_000_000, 'held' => 250_000]);

        $this->actingAs($buyer)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Welcome back, Rahim')
            // Balance, held, available (balance − held) and 80% of available.
            ->assertSee('10,000.00')
            ->assertSee('2,500.00')
            ->assertSee('7,500.00')
            ->assertSee('6,000.00')
            ->assertSee('You have no bids on live auctions');
    }

    public function test_a_buyer_without_a_wallet_still_gets_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
    }

    public function test_it_lists_the_live_auctions_the_buyer_is_bidding_on(): void
    {
        $buyer = User::factory()->create();
        $rival = User::factory()->create();

        $leading = Auction::factory()->live()->create(['title' => 'Brass lamp', 'leader_id' => $buyer->id, 'bid_count' => 1]);
        $outbid = Auction::factory()->live()->create(['title' => 'Oak chair', 'leader_id' => $rival->id, 'bid_count' => 2]);
        $notMine = Auction::factory()->live()->create(['title' => 'Silver spoon', 'leader_id' => $rival->id, 'bid_count' => 1]);
        $finished = Auction::factory()->sold()->create(['title' => 'Copper pot', 'leader_id' => $buyer->id, 'winner_id' => $buyer->id]);

        Bid::factory()->create(['auction_id' => $leading->id, 'user_id' => $buyer->id]);
        Bid::factory()->create(['auction_id' => $outbid->id, 'user_id' => $buyer->id, 'seq' => 1]);
        Bid::factory()->create(['auction_id' => $outbid->id, 'user_id' => $rival->id, 'seq' => 2, 'amount' => 110_000]);
        Bid::factory()->create(['auction_id' => $notMine->id, 'user_id' => $rival->id]);
        Bid::factory()->create(['auction_id' => $finished->id, 'user_id' => $buyer->id]);

        $this->actingAs($buyer)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Brass lamp')
            ->assertSee('Leading')
            ->assertSee('Oak chair')
            ->assertSee('Outbid')
            ->assertDontSee('Silver spoon')
            ->assertDontSee('Copper pot');
    }

    public function test_the_numbers_count_only_this_buyers_activity(): void
    {
        $buyer = User::factory()->create();
        $other = User::factory()->create();

        // Leading two live auctions; the other buyer leads one.
        Auction::factory()->live()->count(2)->create(['leader_id' => $buyer->id]);
        Auction::factory()->live()->create(['leader_id' => $other->id]);

        // Won three; two of the orders are still on the way, one is finished.
        $won = Auction::factory()->sold()->count(3)->create(['leader_id' => $buyer->id, 'winner_id' => $buyer->id]);
        Order::factory()->create(['auction_id' => $won[0]->id]);
        Order::factory()->create(['auction_id' => $won[1]->id, 'status' => OrderStatus::Shipped]);
        Order::factory()->create(['auction_id' => $won[2]->id, 'status' => OrderStatus::Completed]);
        Order::factory()->create();

        // Watching one open auction; a finished one no longer counts.
        $buyer->watchedAuctions()->attach([
            Auction::factory()->scheduled()->create()->id,
            Auction::factory()->unsold()->create()->id,
        ]);

        Livewire::actingAs($buyer)
            ->test(Overview::class)
            ->assertViewHas('stats', ['leading' => 2, 'watching' => 1, 'won' => 3, 'openOrders' => 2]);
    }

    public function test_guests_and_unconfirmed_accounts_cannot_open_it(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');

        $this->actingAs(User::factory()->unverified()->create())
            ->get('/dashboard')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_staff_and_sellers_are_sent_to_their_own_workspace(): void
    {
        $this->actingAs(User::factory()->systemOwner()->create())->get('/dashboard')->assertRedirect('/admin');
        $this->actingAs(User::factory()->companyOwner()->create())->get('/dashboard')->assertRedirect('/seller');
    }
}
