<?php

namespace Tests\Feature\Content;

use App\Actions\Companies\UpdateCompanyProfile;
use App\Filament\Seller\Pages\CompanyProfile;
use App\Models\Company;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\TestCase;

/** A seller's storefront: banner, accent colour, links and reviews. */
class StorefrontTest extends TestCase
{
    use InteractsWithCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_owner_sets_up_the_storefront(): void
    {
        [$company, $owner] = $this->company($this->plan());

        app(UpdateCompanyProfile::class)->handle($owner, $company, [
            'name' => $company->name,
            'accent_color' => '#B91C1C',
            'website_url' => 'https://dhaka-antiques.test',
            'facebook_url' => 'https://www.facebook.com/dhakaantiques',
        ], banner: UploadedFile::fake()->image('banner.jpg', 2000, 500));

        $company->refresh();
        $this->assertSame('#b91c1c', $company->accent_color);
        Storage::disk('public')->assertExists((string) $company->banner_path);

        $this->get(route('sellers.show', $company))->assertOk()
            ->assertSee(Storage::disk('public')->url((string) $company->banner_path), false)
            ->assertSee('background-color: #b91c1c', false)
            ->assertSee('https://dhaka-antiques.test', false)
            ->assertSee('https://www.facebook.com/dhakaantiques', false)
            ->assertSee('rel="nofollow noopener noreferrer ugc"', false);

        // Removing the banner deletes the file.
        $banner = (string) $company->banner_path;
        app(UpdateCompanyProfile::class)->handle($owner, $company, ['name' => $company->name], removeBanner: true);
        Storage::disk('public')->assertMissing($banner);
        $this->assertNull($company->refresh()->banner_path);
    }

    public function test_bad_storefront_values_are_refused(): void
    {
        [$company, $owner] = $this->company($this->plan());

        try {
            app(UpdateCompanyProfile::class)->handle($owner, $company, [
                'name' => $company->name,
                'accent_color' => 'red',
                'website_url' => 'javascript:alert(1)',
                'facebook_url' => 'https://evil.test/facebook.com/',
            ]);
            $this->fail('Bad storefront values were accepted.');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['accent_color', 'website_url', 'facebook_url'], array_keys($e->errors()));
        }
    }

    public function test_the_profile_page_saves_the_storefront(): void
    {
        [$company, $owner] = $this->company($this->plan());
        $this->actingAs($owner);
        Filament::setCurrentPanel('seller');
        Filament::setTenant($company);

        Livewire::test(CompanyProfile::class)
            ->fillForm(['accent_color' => '#0f766e', 'website_url' => 'https://example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('#0f766e', $company->refresh()->accent_color);
        $this->assertSame('https://example.com', $company->website_url);
    }

    public function test_the_reviews_tab_lists_visible_reviews_with_replies(): void
    {
        $company = Company::factory()->create(['rating_sum' => 9, 'rating_count' => 2]);
        $buyer = User::factory()->create(['name' => 'Rahima Khatun']);

        Review::factory()->create([
            'order_id' => Order::factory()->create(['company_id' => $company->id, 'buyer_id' => $buyer->id])->id,
            'rating' => 5,
            'comment' => 'Arrived well packed.',
            'reply' => 'Thank you for buying!',
            'replied_at' => now(),
        ]);
        Review::factory()->create([
            'order_id' => Order::factory()->create(['company_id' => $company->id])->id,
            'comment' => 'Hidden by staff.',
            'is_visible' => false,
        ]);

        $this->get(route('sellers.show', $company).'?tab=reviews')->assertOk()
            ->assertSee('Arrived well packed.')
            ->assertSee('Thank you for buying!')
            ->assertSee($buyer->maskedName())
            ->assertDontSee('Rahima Khatun')
            ->assertDontSee('Hidden by staff.');
    }
}
