<?php

namespace Tests\Feature\Auctions;

use App\Livewire\Auctions\Index;
use App\Models\Auction;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The public auction list: what it shows, and its search, filters and sorting.
 */
class AuctionIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_live_and_scheduled_auctions_by_default_live_first(): void
    {
        Auction::factory()->scheduled()->create(['title' => 'Scheduled oak chair']);
        Auction::factory()->live()->create(['title' => 'Live brass lamp', 'ends_at' => now()->addHours(5)]);
        Auction::factory()->live()->create(['title' => 'Live copper pot', 'ends_at' => now()->addHour()]);

        Auction::factory()->create(['title' => 'Draft teapot']);
        Auction::factory()->pendingReview()->create(['title' => 'Pending vase']);
        Auction::factory()->rejected()->create(['title' => 'Rejected bowl']);
        Auction::factory()->sold()->create(['title' => 'Sold clock']);
        Auction::factory()->unsold()->create(['title' => 'Unsold mirror']);
        Auction::factory()->cancelled()->create(['title' => 'Cancelled rug']);

        $this->get('/auctions')
            ->assertOk()
            // Live ones first, the one ending soonest on top; then scheduled.
            ->assertSeeInOrder(['Live copper pot', 'Live brass lamp', 'Scheduled oak chair'])
            ->assertSee('3 auctions')
            ->assertDontSee('Draft teapot')
            ->assertDontSee('Pending vase')
            ->assertDontSee('Rejected bowl')
            ->assertDontSee('Sold clock')
            ->assertDontSee('Unsold mirror')
            ->assertDontSee('Cancelled rug');
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        Auction::factory()->live()->create(['title' => 'Live brass lamp']);
        Auction::factory()->scheduled()->create(['title' => 'Scheduled oak chair']);
        Auction::factory()->sold()->create(['title' => 'Sold clock']);
        Auction::factory()->unsold()->create(['title' => 'Unsold mirror']);
        Auction::factory()->cancelled()->create(['title' => 'Cancelled rug']);

        Livewire::test(Index::class)
            ->set('status', 'live')
            ->assertSee('Live brass lamp')
            ->assertDontSee('Scheduled oak chair')
            ->set('status', 'upcoming')
            ->assertSee('Scheduled oak chair')
            ->assertDontSee('Live brass lamp')
            ->set('status', 'ended')
            ->assertSee('Sold clock')
            ->assertSee('Unsold mirror')
            ->assertDontSee('Live brass lamp')
            // Cancelled auctions are never public, whatever the filter.
            ->assertDontSee('Cancelled rug');
    }

    public function test_a_made_up_status_or_sort_in_the_address_falls_back_to_the_default(): void
    {
        Auction::factory()->live()->create(['title' => 'Live brass lamp']);
        Auction::factory()->create(['title' => 'Draft teapot']);

        $this->get('/auctions?status=draft&sort=id;drop')
            ->assertOk()
            ->assertSee('Live brass lamp')
            ->assertDontSee('Draft teapot');
    }

    public function test_search_matches_the_auction_title_and_the_product_name(): void
    {
        Auction::factory()->live()->create(['title' => 'Antique brass lamp']);
        Auction::factory()->live()->create([
            'title' => 'Lot 42',
            'product_id' => Product::factory()->create(['name' => 'Brass telescope']),
        ]);
        Auction::factory()->live()->create(['title' => 'Oak writing desk']);

        Livewire::test(Index::class)
            ->set('search', 'brass')
            ->assertSee('Antique brass lamp')
            ->assertSee('Lot 42')
            ->assertDontSee('Oak writing desk')
            ->assertSee('2 auctions');

        // The search arrives through the address too (the home page search box).
        $this->get('/auctions?q=oak')->assertSee('Oak writing desk')->assertDontSee('Antique brass lamp');
    }

    public function test_search_treats_wildcard_characters_as_plain_text(): void
    {
        Auction::factory()->live()->create(['title' => '100% cotton saree']);
        Auction::factory()->live()->create(['title' => 'Silk saree']);

        Livewire::test(Index::class)
            ->set('search', '%')
            ->assertSee('100% cotton saree')
            ->assertDontSee('Silk saree')
            ->set('search', '_')
            ->assertSee('No auctions match');
    }

    public function test_the_category_filter_includes_sub_categories(): void
    {
        $vehicles = Category::factory()->create(['name' => 'Vehicles', 'slug' => 'vehicles']);
        $cars = Category::factory()->create(['name' => 'Cars', 'slug' => 'cars', 'parent_id' => $vehicles->id]);
        $art = Category::factory()->create(['name' => 'Art', 'slug' => 'art']);

        $this->auctionIn($vehicles, 'Vintage tractor');
        $this->auctionIn($cars, 'Toyota Corolla');
        $this->auctionIn($art, 'Oil painting');

        Livewire::test(Index::class)
            ->set('category', 'vehicles')
            ->assertSee('Vintage tractor')
            ->assertSee('Toyota Corolla')
            ->assertDontSee('Oil painting')
            ->set('category', 'cars')
            ->assertSee('Toyota Corolla')
            ->assertDontSee('Vintage tractor')
            ->set('category', 'no-such-category')
            ->assertSee('No auctions match');
    }

    public function test_the_seller_filter_shows_one_company(): void
    {
        $first = Company::factory()->create(['name' => 'Dhaka Antiques', 'slug' => 'dhaka-antiques']);
        $second = Company::factory()->create(['name' => 'Sylhet Crafts', 'slug' => 'sylhet-crafts']);
        Company::factory()->create(['name' => 'Company without auctions']);

        Auction::factory()->live()->create(['title' => 'Brass lamp', 'company_id' => $first->id]);
        Auction::factory()->live()->create(['title' => 'Cane basket', 'company_id' => $second->id]);

        Livewire::test(Index::class)
            // Only sellers with something to show are offered.
            ->assertSee('Sylhet Crafts')
            ->assertDontSee('Company without auctions')
            ->set('company', 'dhaka-antiques')
            ->assertSee('Brass lamp')
            ->assertDontSee('Cane basket');
    }

    public function test_the_price_filter_uses_the_current_price_in_major_units(): void
    {
        Auction::factory()->live()->create(['title' => 'Cheap pen', 'starting_price' => 50_000]);
        Auction::factory()->live()->create(['title' => 'Mid camera', 'starting_price' => 250_000]);
        Auction::factory()->live()->create(['title' => 'Dear watch', 'starting_price' => 900_000]);

        Livewire::test(Index::class)
            ->set('minPrice', '1000')
            ->assertSee('Mid camera')
            ->assertSee('Dear watch')
            ->assertDontSee('Cheap pen')
            ->set('maxPrice', '2,500.00')
            ->assertSee('Mid camera')
            ->assertDontSee('Dear watch')
            // Something that is not a price is ignored instead of failing.
            ->set('minPrice', 'abc')
            ->set('maxPrice', '-5')
            ->assertSee('Cheap pen')
            ->assertSee('Dear watch');
    }

    public function test_sorting(): void
    {
        Auction::factory()->live()->create(['title' => 'Alpha', 'starting_price' => 300_000, 'bid_count' => 1, 'ends_at' => now()->addHours(3)]);
        Auction::factory()->live()->create(['title' => 'Bravo', 'starting_price' => 100_000, 'bid_count' => 9, 'ends_at' => now()->addHours(1)]);
        Auction::factory()->live()->create(['title' => 'Charlie', 'starting_price' => 200_000, 'bid_count' => 4, 'ends_at' => now()->addHours(2)]);

        Livewire::test(Index::class)
            ->assertSeeInOrder(['Bravo', 'Charlie', 'Alpha'])
            ->set('sort', 'price_low')
            ->assertSeeInOrder(['Bravo', 'Charlie', 'Alpha'])
            ->set('sort', 'price_high')
            ->assertSeeInOrder(['Alpha', 'Charlie', 'Bravo'])
            ->set('sort', 'bids')
            ->assertSeeInOrder(['Bravo', 'Charlie', 'Alpha'])
            ->set('sort', 'newest')
            ->assertSeeInOrder(['Charlie', 'Bravo', 'Alpha']);
    }

    public function test_the_list_is_paginated_and_a_filter_change_returns_to_the_first_page(): void
    {
        Auction::factory()->live()->count(13)->sequence(
            fn ($sequence) => ['title' => 'Lot number '.str_pad((string) ($sequence->index + 1), 2, '0', STR_PAD_LEFT), 'ends_at' => now()->addHours($sequence->index + 1)],
        )->create();

        Livewire::test(Index::class)
            ->assertSee('13 auctions')
            ->assertSee('Lot number 12')
            ->assertDontSee('Lot number 13')
            ->call('gotoPage', 2)
            ->assertSee('Lot number 13')
            ->assertDontSee('Lot number 12')
            ->set('search', 'Lot number 0')
            ->assertSee('Lot number 01')
            ->assertSee('9 auctions');
    }

    public function test_filters_can_be_cleared(): void
    {
        Auction::factory()->live()->create(['title' => 'Brass lamp']);

        Livewire::test(Index::class)
            ->assertDontSee('Clear filters')
            ->set('search', 'nothing like this')
            ->set('status', 'ended')
            ->assertSee('No auctions match')
            ->assertSee('Clear filters')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('status', 'open')
            ->assertSee('Brass lamp');
    }

    public function test_the_list_does_not_run_a_query_per_auction(): void
    {
        Auction::factory()->live()->count(3)->create();
        $few = $this->countQueries(fn () => $this->get('/auctions')->assertOk());

        Auction::factory()->live()->count(8)->create();
        $many = $this->countQueries(fn () => $this->get('/auctions')->assertOk());

        $this->assertSame($few, $many);
    }

    private function auctionIn(Category $category, string $title): Auction
    {
        return Auction::factory()->live()->create([
            'title' => $title,
            'product_id' => Product::factory()->create(['category_id' => $category->id]),
        ]);
    }

    private function countQueries(callable $callback): int
    {
        $connection = $this->app['db']->connection();

        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $callback();
        $connection->disableQueryLog();

        return count($connection->getQueryLog());
    }
}
