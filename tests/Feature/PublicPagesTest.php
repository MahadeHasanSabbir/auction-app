<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\ProductImage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_render(): void
    {
        $this->get('/')->assertOk();
        $this->get('/about')->assertOk();
        $this->get('/auctions')->assertOk()->assertSee('There are no live or upcoming auctions right now.');
    }

    public function test_the_auction_list_shows_live_and_scheduled_auctions_only(): void
    {
        $live = Auction::factory()->live()->create(['title' => 'Live brass lamp']);
        $scheduled = Auction::factory()->scheduled()->create(['title' => 'Scheduled oak chair']);
        ProductImage::factory()->create(['product_id' => $live->product_id]);

        Auction::factory()->create(['title' => 'Draft teapot']);
        Auction::factory()->pendingReview()->create(['title' => 'Pending vase']);
        Auction::factory()->sold()->create(['title' => 'Sold clock']);
        Auction::factory()->cancelled()->create(['title' => 'Cancelled rug']);

        $this->get('/auctions')
            ->assertOk()
            ->assertSeeInOrder([$live->title, $scheduled->title])
            ->assertDontSee('Draft teapot')
            ->assertDontSee('Pending vase')
            ->assertDontSee('Sold clock')
            ->assertDontSee('Cancelled rug');
    }

    public function test_the_auction_list_renders_the_demo_data_for_guests_and_buyers(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get('/auctions')->assertOk()->assertSee('Mughal-era brass water jug');

        $buyer = User::query()->where('email', 'buyer1@auction.com')->sole();

        $this->actingAs($buyer)->get('/auctions')->assertOk();
        $this->actingAs($buyer)->get('/dashboard')->assertOk()->assertSee('Buyer');
    }
}
