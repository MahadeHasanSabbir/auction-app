<?php

namespace Tests\Feature\Auctions;

use App\Enums\AuctionStatus;
use App\Livewire\Account\Bids;
use App\Livewire\Auctions\Show;
use App\Models\Auction;
use App\Models\Company;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fees\CommissionTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** The bid form on the auction page and the buyer's "My bids" page. */
class BiddingPagesTest extends TestCase
{
    use InteractsWithLedger;
    use RefreshDatabase;

    private Auction $auction;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        $this->auction = Auction::factory()->live()->create([
            'starting_price' => 100_000,
            'min_increment' => 5_000,
            'buy_now_price' => 400_000,
            'fee_snapshot' => ['sale_commission' => CommissionTerms::none()->toSnapshot()],
        ]);
        $this->buyer = User::factory()->create();
        $this->fund(Wallet::factory()->create(['user_id' => $this->buyer->id]), 1_000_000);
    }

    public function test_a_guest_is_asked_to_sign_in(): void
    {
        $this->get(route('auctions.show', $this->auction))->assertOk()->assertSee('Sign in to bid');

        Livewire::test(Show::class, ['auction' => $this->auction])->call('placeBid')->assertRedirect(route('login'));
    }

    public function test_a_buyer_bids_and_then_sees_they_lead(): void
    {
        $this->actingAs($this->buyer);

        Livewire::test(Show::class, ['auction' => $this->auction])
            ->assertSee('Place bid')
            ->assertSee('Buy now for')
            ->set('amount', '1000')
            ->call('placeBid')
            ->assertHasNoErrors()
            ->assertDispatched('toast')
            ->assertSee('You are the highest bidder.')
            ->assertDontSee('Buy now for');

        $this->assertSame($this->buyer->id, $this->auction->refresh()->leader_id);
    }

    public function test_a_refused_bid_shows_why(): void
    {
        $this->actingAs($this->buyer);

        Livewire::test(Show::class, ['auction' => $this->auction])
            ->set('amount', '10')
            ->call('placeBid')
            ->assertHasErrors('amount');

        $this->assertSame(0, $this->auction->refresh()->bid_count);
    }

    public function test_an_automatic_maximum_is_shown_only_to_its_owner(): void
    {
        $this->actingAs($this->buyer);
        Livewire::test(Show::class, ['auction' => $this->auction])
            ->set('auto', true)
            ->set('amount', '2500')
            ->call('placeBid')
            ->assertSee('Your automatic maximum');

        $other = User::factory()->create();
        $this->actingAs($other)->get(route('auctions.show', $this->auction))
            ->assertOk()
            ->assertDontSee('2,500.00');
    }

    public function test_buy_now_and_the_watchlist_from_the_page(): void
    {
        $this->actingAs($this->buyer);

        Livewire::test(Show::class, ['auction' => $this->auction])
            ->call('toggleWatch')
            ->assertSee('Watching')
            ->call('buyNow')
            ->assertHasNoErrors()
            ->assertSee('You won this auction.');

        $this->assertSame(AuctionStatus::Sold, $this->auction->refresh()->status);
    }

    public function test_a_seller_sees_that_they_cannot_bid(): void
    {
        $this->actingAs(User::factory()->companyOwner(Company::factory()->create())->create())
            ->get(route('auctions.show', $this->auction))
            ->assertOk()
            ->assertSee('Seller and staff accounts cannot bid');
    }

    public function test_my_bids_lists_each_tab(): void
    {
        $this->actingAs($this->buyer);
        Livewire::test(Show::class, ['auction' => $this->auction])->set('amount', '1000')->call('placeBid');

        $won = Auction::factory()->sold()->create(['winner_id' => $this->buyer->id, 'leader_id' => $this->buyer->id]);
        $watched = Auction::factory()->scheduled()->create();
        $this->buyer->watchedAuctions()->attach($watched->id, ['created_at' => now()]);

        $this->get(route('bids'))->assertOk()->assertSee($this->auction->title)->assertSee('Leading');

        Livewire::test(Bids::class)
            ->set('tab', 'won')->assertSee($won->title)
            ->set('tab', 'watching')->assertSee($watched->title)
            ->set('tab', 'lost')->assertSee('Nothing here');
    }

    public function test_sellers_are_sent_from_my_bids_to_their_panel(): void
    {
        $this->actingAs(User::factory()->companyOwner(Company::factory()->create())->create())
            ->get(route('bids'))
            ->assertRedirect('/seller');
    }
}
