<?php

namespace Tests\Feature\Panels;

use App\Enums\AuctionStatus;
use App\Filament\Admin\Resources\Auctions\Pages\ListAuctions as AdminListAuctions;
use App\Filament\Admin\Resources\Auctions\Pages\ViewAuction as AdminViewAuction;
use App\Filament\Seller\Resources\Auctions\Pages\CreateAuction;
use App\Filament\Seller\Resources\Auctions\Pages\EditAuction;
use App\Filament\Seller\Resources\Auctions\Pages\ViewAuction;
use App\Filament\Seller\Resources\Products\Pages\CreateProduct;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Models\Auction;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\TestCase;

/** Products and auctions in the seller panel, and the review queue in the admin panel. */
class ListingPagesTest extends TestCase
{
    use InteractsWithCompanies;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        [$this->company, $this->owner] = $this->company($this->plan());
        $this->member = User::factory()->companyMember($this->company)->create();
    }

    private function openSeller(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('seller');
        Filament::setTenant($this->company);
    }

    private function product(): Product
    {
        $product = Product::factory()->create(['company_id' => $this->company->id]);
        $image = ProductImage::factory()->create(['product_id' => $product->id]);
        Storage::disk('public')->put($image->path, (string) UploadedFile::fake()->image('p.jpg')->get());

        return $product;
    }

    public function test_the_team_opens_every_seller_page(): void
    {
        $product = $this->product();
        $auction = Auction::factory()->rejected()->create(['company_id' => $this->company->id, 'product_id' => $product->id]);
        $base = "/seller/{$this->company->slug}";

        $this->actingAs($this->member);
        $this->get("{$base}/products")->assertOk()->assertSee($product->name);
        $this->get("{$base}/products/create")->assertOk();
        $this->get("{$base}/products/{$product->id}")->assertOk();
        $this->get("{$base}/auctions")->assertOk()->assertSee($auction->title);
        $this->get("{$base}/auctions/create?product={$product->id}")->assertOk();
        $this->get("{$base}/auctions/{$auction->id}")->assertOk()->assertSee('The photos do not show the item clearly.');
        $this->get("{$base}/auctions/{$auction->id}/edit")->assertOk();
    }

    public function test_another_companys_records_are_not_found(): void
    {
        [$other] = $this->company($this->plan());
        $product = Product::factory()->create(['company_id' => $other->id]);
        $auction = Auction::factory()->create(['company_id' => $other->id, 'product_id' => $product->id]);
        $base = "/seller/{$this->company->slug}";

        $this->actingAs($this->member);
        $this->get("{$base}/products/{$product->id}")->assertNotFound();
        $this->get("{$base}/auctions/{$auction->id}")->assertNotFound();
    }

    public function test_a_product_is_added_with_pictures_through_the_form(): void
    {
        $this->openSeller($this->member);
        $category = Category::factory()->create();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Teak writing desk',
                'category_id' => $category->id,
                'condition' => 'used',
                'description' => 'Solid teak, three drawers.',
                'attributes' => ['Width' => '120 cm'],
                'images' => [UploadedFile::fake()->image('desk.jpg', 800, 600)],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Teak writing desk')->firstOrFail();
        $this->assertSame($this->company->id, $product->company_id);
        $this->assertSame(1, $product->images()->count());
    }

    public function test_the_form_shows_the_actions_refusal_under_the_field(): void
    {
        $this->openSeller($this->member);
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Desk', 'category_id' => $parent->id])
            ->call('create')
            ->assertHasFormErrors(['category_id']);
    }

    public function test_a_frozen_product_opens_read_only(): void
    {
        $product = $this->product();
        Auction::factory()->pendingReview()->create(['company_id' => $this->company->id, 'product_id' => $product->id]);

        $this->actingAs($this->member);
        $this->get("/seller/{$this->company->slug}/products/{$product->id}/edit")->assertForbidden();
        $this->get("/seller/{$this->company->slug}/products/{$product->id}")->assertOk();
    }

    public function test_the_edit_form_keeps_existing_pictures(): void
    {
        $product = $this->product();
        $this->openSeller($this->member);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['name' => 'Renamed lamp'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed lamp', $product->refresh()->name);
        $this->assertSame(1, $product->images()->count());
    }

    public function test_an_auction_is_drafted_and_sent_for_review_from_the_panel(): void
    {
        $product = $this->product();
        $this->openSeller($this->member);

        Livewire::test(CreateAuction::class)
            ->fillForm([
                'product_id' => $product->id,
                'title' => 'Teak desk',
                'starting_price' => '5000',
                'min_increment' => '100',
                'reserve_price' => '',
                'buy_now_price' => '9000',
                'proposed_starts_at' => now()->addDay()->startOfHour(),
                'proposed_ends_at' => now()->addDays(4)->startOfHour(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $auction = Auction::query()->where('title', 'Teak desk')->firstOrFail();
        $this->assertSame(500_000, $auction->starting_price->amount);
        $this->assertSame(900_000, $auction->buy_now_price?->amount);

        Livewire::test(ViewAuction::class, ['record' => $auction->getRouteKey()])
            ->callAction('submit')
            ->assertNotified();

        $this->assertSame(AuctionStatus::PendingReview, $auction->refresh()->status);
    }

    public function test_a_price_refusal_is_shown_on_the_field(): void
    {
        $product = $this->product();
        $auction = Auction::factory()->create(['company_id' => $this->company->id, 'product_id' => $product->id]);
        $this->openSeller($this->member);

        Livewire::test(EditAuction::class, ['record' => $auction->getRouteKey()])
            ->fillForm(['reserve_price' => '1'])
            ->call('save')
            ->assertHasFormErrors(['reserve_price']);
    }

    public function test_a_member_does_not_see_cancel_on_an_approved_auction_and_the_owner_does(): void
    {
        $auction = Auction::factory()->scheduled()->create(['company_id' => $this->company->id, 'product_id' => $this->product()->id]);

        $this->openSeller($this->member);
        Livewire::test(ViewAuction::class, ['record' => $auction->getRouteKey()])->assertActionHidden('cancel');

        $this->openSeller($this->owner);
        Livewire::test(ViewAuction::class, ['record' => $auction->getRouteKey()])
            ->assertActionVisible('cancel')
            ->callAction('cancel');

        $this->assertSame(AuctionStatus::Cancelled, $auction->refresh()->status);
    }

    public function test_staff_review_auctions_from_the_queue(): void
    {
        $waiting = Auction::factory()->pendingReview()->create(['company_id' => $this->company->id, 'product_id' => $this->product()->id]);
        $other = Auction::factory()->pendingReview()->create(['company_id' => $this->company->id, 'product_id' => $this->product()->id]);
        $staff = User::factory()->platformStaff()->create();

        $this->actingAs($staff);
        $this->get('/admin/auctions')->assertOk()->assertSee($waiting->title);
        $this->get("/admin/auctions/{$waiting->id}")->assertOk();
        $this->get('/admin')->assertOk()->assertSee('Auctions to review');

        Filament::setCurrentPanel('admin');

        Livewire::test(AdminListAuctions::class)
            ->assertCanSeeTableRecords([$waiting, $other])
            ->callAction(TestAction::make('approve')->table($waiting), [
                'starts_at' => now()->addDay()->startOfMinute(),
                'ends_at' => now()->addDays(3)->startOfMinute(),
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(AuctionStatus::Scheduled, $waiting->refresh()->status);
        $this->assertSame($staff->id, $waiting->reviewed_by);

        Livewire::test(AdminViewAuction::class, ['record' => $other->getRouteKey()])
            ->callAction('reject', ['reason' => 'Blurry photos.']);

        $this->assertSame(AuctionStatus::Rejected, $other->refresh()->status);
        $this->assertSame('Blurry photos.', $other->rejection_reason);
    }

    public function test_sellers_and_buyers_cannot_open_the_review_queue(): void
    {
        $this->actingAs($this->owner)->get('/admin/auctions')->assertForbidden();
        $this->actingAs(User::factory()->create())->get("/seller/{$this->company->slug}/auctions")->assertForbidden();
    }
}
