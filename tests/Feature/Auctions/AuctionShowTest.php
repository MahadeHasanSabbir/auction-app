<?php

namespace Tests\Feature\Auctions;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public page of one auction.
 */
class AuctionShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_live_auction_shows_its_details(): void
    {
        $parent = Category::factory()->create(['name' => 'Collectibles']);
        $category = Category::factory()->create(['name' => 'Antiques', 'parent_id' => $parent->id]);
        $company = Company::factory()->create(['name' => 'Dhaka Antiques']);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'description' => 'A hand-beaten brass jug with its original lid.',
        ]);
        ProductImage::factory()->count(2)->create(['product_id' => $product->id]);

        $auction = Auction::factory()->live()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'title' => 'Mughal-era brass water jug',
            'starting_price' => 1_200_000,
            'min_increment' => 60_000,
        ]);

        $this->get("/auctions/{$auction->id}")
            ->assertOk()
            ->assertSee('Mughal-era brass water jug')
            ->assertSee('A hand-beaten brass jug with its original lid.')
            ->assertSee('Collectibles')
            ->assertSee('Antiques')
            ->assertSee('Dhaka Antiques')
            ->assertSee('Starting price')
            ->assertSee('12,000.00')
            // The first bid may be the starting price itself.
            ->assertSee('Next bid from')
            ->assertSee('Sign in to bid')
            ->assertSee('No bids were placed.');
    }

    public function test_auctions_that_are_not_public_do_not_exist_for_anyone_on_the_public_site(): void
    {
        $hidden = [
            Auction::factory()->create(),
            Auction::factory()->pendingReview()->create(),
            Auction::factory()->rejected()->create(),
            Auction::factory()->cancelled()->create(),
        ];

        $owner = User::factory()->companyOwner($hidden[0]->company_id)->create();

        foreach ($hidden as $auction) {
            $this->get("/auctions/{$auction->id}")->assertNotFound();
        }

        // Sellers manage their drafts in the seller panel, not here.
        $this->actingAs($owner)->get("/auctions/{$hidden[0]->id}")->assertNotFound();
        $this->get('/auctions/999999')->assertNotFound();
    }

    public function test_scheduled_sold_and_unsold_auctions_can_be_viewed(): void
    {
        $scheduled = Auction::factory()->scheduled()->create();
        $sold = Auction::factory()->sold()->create();
        $unsold = Auction::factory()->unsold()->create();

        $this->get("/auctions/{$scheduled->id}")->assertOk()->assertSee('Bidding has not started yet.')->assertSee('Starts in');
        $this->get("/auctions/{$sold->id}")->assertOk()->assertSee('Sold for')->assertSee('Won by')->assertDontSee('Next bid from');
        $this->get("/auctions/{$unsold->id}")->assertOk()->assertDontSee('Sign in to bid');
    }

    public function test_bidders_are_shown_with_masked_names_newest_bid_first(): void
    {
        $auction = Auction::factory()->live()->create(['bid_count' => 3]);
        $karim = User::factory()->create(['name' => 'Tamim Karim', 'email' => 'tamim@example.com']);
        $das = User::factory()->create(['name' => 'Priya Das']);

        Bid::factory()->create(['auction_id' => $auction->id, 'user_id' => $karim->id, 'amount' => 105_000, 'seq' => 1]);
        Bid::factory()->create(['auction_id' => $auction->id, 'user_id' => $das->id, 'amount' => 110_000, 'seq' => 2]);
        Bid::factory()->create(['auction_id' => $auction->id, 'user_id' => $karim->id, 'amount' => 115_000, 'seq' => 3]);

        $this->get("/auctions/{$auction->id}")
            ->assertOk()
            ->assertSeeInOrder(['1,150.00', '1,100.00', '1,050.00'])
            ->assertSeeInOrder(['T***m K.', 'Leading', 'P***a D.', 'T***m K.'])
            // Nothing that identifies a bidder reaches the page.
            ->assertDontSee('Tamim')
            ->assertDontSee('Karim')
            ->assertDontSee('Priya')
            ->assertDontSee('tamim@example.com');
    }

    public function test_a_bidder_sees_their_own_bids_as_theirs(): void
    {
        $buyer = User::factory()->create(['name' => 'Tamim Karim']);
        $auction = Auction::factory()->live()->create(['bid_count' => 1, 'leader_id' => $buyer->id, 'current_price' => 105_000]);
        Bid::factory()->create(['auction_id' => $auction->id, 'user_id' => $buyer->id, 'amount' => 105_000, 'seq' => 1]);

        $this->actingAs($buyer)
            ->get("/auctions/{$auction->id}")
            ->assertOk()
            ->assertSee('You are the highest bidder.')
            ->assertDontSee('T***m K.')
            ->assertDontSee('Sign in to bid');
    }

    public function test_only_the_latest_bids_are_listed(): void
    {
        $auction = Auction::factory()->live()->create(['bid_count' => 18]);
        $bidder = User::factory()->create();

        foreach (range(1, 18) as $seq) {
            Bid::factory()->create(['auction_id' => $auction->id, 'user_id' => $bidder->id, 'amount' => 100_000 + $seq * 5_000, 'seq' => $seq]);
        }

        $this->get("/auctions/{$auction->id}")
            ->assertOk()
            ->assertSee('Showing the latest 15 bids.')
            ->assertSee('1,900.00')
            ->assertSee('1,200.00')
            // The three oldest are left out.
            ->assertDontSee('1,150.00');
    }

    public function test_the_reserve_amount_is_never_shown(): void
    {
        $unmet = Auction::factory()->live()->create(['starting_price' => 100_000, 'reserve_price' => 777_700, 'reserve_met' => false]);
        $met = Auction::factory()->live()->create(['starting_price' => 100_000, 'reserve_price' => 888_800, 'reserve_met' => true]);
        $none = Auction::factory()->live()->create();

        $this->get("/auctions/{$unmet->id}")->assertSee('Reserve price not yet met')->assertDontSee('7,777');
        $this->get("/auctions/{$met->id}")->assertSee('Reserve price met')->assertDontSee('8,888');
        $this->get("/auctions/{$none->id}")->assertDontSee('Reserve price');

        // Nor in the list.
        $this->get('/auctions')->assertDontSee('7,777')->assertDontSee('8,888');
    }

    public function test_a_buy_now_price_is_shown_while_the_auction_is_open(): void
    {
        $auction = Auction::factory()->live()->create(['buy_now_price' => 654_300]);

        $this->get("/auctions/{$auction->id}")->assertSee('Buy now price')->assertSee('6,543.00');
    }

    public function test_the_page_still_opens_when_the_product_was_deleted(): void
    {
        $auction = Auction::factory()->sold()->create(['title' => 'Sold carved chair']);
        $auction->product()->withTrashed()->sole()->delete();

        $this->get("/auctions/{$auction->id}")->assertOk()->assertSee('Sold carved chair');
    }

    public function test_times_are_shown_in_the_viewers_timezone(): void
    {
        $this->travelTo('2026-03-10 06:00:00');

        $auction = Auction::factory()->live()->create(['ends_at' => '2026-03-10 18:30:00']);

        // Guests get the marketplace default (Asia/Dhaka, UTC+6).
        $this->get("/auctions/{$auction->id}")->assertSee('11 Mar 2026, 12:30 AM');

        $viewer = User::factory()->create(['timezone' => 'Europe/London']);

        $this->actingAs($viewer)->get("/auctions/{$auction->id}")->assertSee('10 Mar 2026, 6:30 PM');
    }
}
