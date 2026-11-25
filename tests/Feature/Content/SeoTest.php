<?php

namespace Tests\Feature\Content;

use App\Enums\CompanyStatus;
use App\Enums\SitePage;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Addresses with slugs, meta tags, the sitemap and robots.txt. */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_auction_links_carry_the_title_and_the_id_alone_still_works(): void
    {
        $auction = Auction::factory()->live()->create(['title' => 'Brass Jug, 1920s!']);

        $this->assertStringEndsWith('/auctions/'.$auction->id.'/brass-jug-1920s', $auction->url());

        $this->get($auction->url())->assertOk()->assertSee('Brass Jug, 1920s!');
        $this->get('/auctions/'.$auction->id)->assertOk()
            ->assertSee('<link rel="canonical" href="'.$auction->url().'">', false);
        $this->get('/auctions')->assertSee($auction->url(), false);
    }

    public function test_the_auction_page_has_meta_tags_for_link_previews(): void
    {
        $product = Product::factory()->create(['description' => 'A heavy brass jug from an old Dhaka house.']);
        $auction = Auction::factory()->live()->create(['product_id' => $product->id, 'company_id' => $product->company_id, 'title' => 'Brass jug']);

        $this->get($auction->url())->assertOk()
            ->assertSee('<meta name="description" content="A heavy brass jug from an old Dhaka house.">', false)
            ->assertSee('<meta property="og:title" content="Brass jug · '.config('app.name').'">', false);
    }

    public function test_sign_in_pages_are_kept_out_of_search_engines(): void
    {
        $this->get('/login')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->get('/')->assertDontSee('noindex', false);
    }

    public function test_the_sitemap_lists_public_pages_only(): void
    {
        Cache::forget('sitemap.xml');

        $live = Auction::factory()->live()->create(['title' => 'Old clock']);
        $sold = Auction::factory()->sold()->create();
        $draft = Auction::factory()->create();
        $pending = Company::factory()->create(['status' => CompanyStatus::Pending]);
        Page::factory()->create(['slug' => SitePage::Faq]);

        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = (string) $response->getContent();

        $this->assertStringContainsString('<loc>'.$live->url().'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.$sold->url().'</loc>', $xml);
        $this->assertStringNotContainsString('/auctions/'.$draft->id.'/', $xml);
        $this->assertStringContainsString('<loc>'.route('sellers.show', $live->company).'</loc>', $xml);
        $this->assertStringNotContainsString(route('sellers.show', $pending), $xml);
        $this->assertStringContainsString('<loc>'.route('pages.faq').'</loc>', $xml);
        $this->assertStringNotContainsString(route('pages.terms'), $xml, 'A page with no text is not listed.');
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_robots_txt_points_to_the_sitemap_and_hides_private_areas(): void
    {
        $this->get('/robots.txt')->assertOk()
            ->assertSee('Disallow: /admin')
            ->assertSee('Disallow: /wallet')
            ->assertSee('Sitemap: '.route('sitemap'));
    }
}
