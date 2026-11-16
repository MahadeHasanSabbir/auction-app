<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\Category;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_render_on_an_empty_marketplace(): void
    {
        $this->get('/')->assertOk()->assertSee('Nothing is live at the moment');
        $this->get('/about')->assertOk()->assertSee('For buyers')->assertSee('For sellers');
        $this->get('/auctions')->assertOk()->assertSee('No auctions match');
    }

    public function test_the_home_page_shows_live_and_scheduled_auctions_and_categories(): void
    {
        Category::factory()->create(['name' => 'Rare maps']);
        Category::factory()->create(['name' => 'Hidden category', 'is_active' => false]);

        Auction::factory()->live()->create(['title' => 'Live brass lamp']);
        Auction::factory()->scheduled()->create(['title' => 'Scheduled oak chair']);
        Auction::factory()->create(['title' => 'Draft teapot']);
        Auction::factory()->sold()->create(['title' => 'Sold clock']);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Ending soon', 'Live brass lamp', 'Rare maps', 'Opening soon', 'Scheduled oak chair'])
            ->assertDontSee('Hidden category')
            ->assertDontSee('Draft teapot')
            ->assertDontSee('Sold clock');
    }

    public function test_the_header_offers_sign_in_to_guests_and_the_workspace_to_members(): void
    {
        $this->get('/')
            ->assertSee('Sign in')
            ->assertSee('Create account')
            ->assertDontSee('Sign out');

        $buyer = User::factory()->create(['name' => 'Rahim Uddin']);
        Wallet::factory()->create(['user_id' => $buyer->id, 'balance' => 250_000, 'held' => 50_000]);

        // A buyer sees the money they can still use.
        $this->actingAs($buyer)->get('/')
            ->assertSee('Rahim Uddin')
            ->assertSee('My account')
            ->assertSee('2,000.00')
            ->assertSee('Sign out')
            ->assertDontSee('Create account');

        $this->actingAs(User::factory()->companyOwner()->create())->get('/')
            ->assertSee('Seller panel')
            ->assertDontSee('Available balance');

        $this->actingAs(User::factory()->platformStaff()->create())->get('/about')
            ->assertSee('Admin panel');
    }

    public function test_every_page_renders_with_the_demo_data_for_each_kind_of_visitor(): void
    {
        $this->seed(DatabaseSeeder::class);

        $visitors = [
            null,
            User::query()->where('email', 'buyer1@auction.com')->sole(),
            User::query()->where('email', 'owner@dhaka-antiques.test')->sole(),
            User::query()->where('email', 'staff@auction.com')->sole(),
        ];

        $auctions = Auction::query()->visibleToPublic()->pluck('id');
        $this->assertGreaterThan(5, $auctions->count());

        foreach ($visitors as $visitor) {
            if ($visitor !== null) {
                $this->actingAs($visitor);
            }

            $this->get('/')->assertOk()->assertSee('Mughal-era brass water jug');
            $this->get('/about')->assertOk();
            $this->get('/auctions')->assertOk();
            $this->get('/auctions?status=ended')->assertOk();

            foreach ($auctions as $id) {
                $this->get("/auctions/{$id}")->assertOk();
            }
        }

        $this->actingAs($visitors[1])->get('/dashboard')->assertOk();
        $this->get('/profile')->assertOk();
    }
}
