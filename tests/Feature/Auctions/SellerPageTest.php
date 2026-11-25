<?php

namespace Tests\Feature\Auctions;

use App\Enums\CompanyStatus;
use App\Models\Auction;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A seller's public page. */
class SellerPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_the_seller_and_their_open_and_sold_auctions(): void
    {
        $company = Company::factory()->create([
            'name' => 'Dhaka Antiques',
            'about' => 'Old things, carefully kept.',
            'email' => 'private@dhaka-antiques.test',
            'phone' => '01700000000',
            'verified_at' => now(),
        ]);
        $live = Auction::factory()->live()->create(['company_id' => $company->id, 'title' => 'Brass jug']);
        $sold = Auction::factory()->sold()->create(['company_id' => $company->id, 'title' => 'Teak chest']);
        Auction::factory()->create(['company_id' => $company->id, 'title' => 'Secret draft']);

        $this->get(route('sellers.show', $company))
            ->assertOk()
            ->assertSee('Dhaka Antiques')
            ->assertSee('Old things, carefully kept.')
            ->assertSee('Verified seller')
            ->assertSee($live->title)
            ->assertDontSee($sold->title)
            ->assertDontSee('Secret draft')
            // Contact details stay private.
            ->assertDontSee('private@dhaka-antiques.test')
            ->assertDontSee('01700000000');

        $this->get(route('sellers.show', $company).'?tab=sold')
            ->assertOk()
            ->assertSee($sold->title)
            ->assertDontSee($live->title);
    }

    public function test_the_auction_page_links_to_the_seller(): void
    {
        $auction = Auction::factory()->live()->create();

        $this->get(route('auctions.show', $auction))
            ->assertOk()
            ->assertSee(route('sellers.show', $auction->company));
    }

    public function test_a_company_not_yet_approved_has_no_page(): void
    {
        $company = Company::factory()->create(['status' => CompanyStatus::Pending]);

        $this->get(route('sellers.show', $company))->assertNotFound();
        $this->get('/sellers/no-such-seller')->assertNotFound();
    }
}
