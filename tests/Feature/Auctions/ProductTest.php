<?php

namespace Tests\Feature\Auctions;

use App\Actions\Catalogue\DeleteProduct;
use App\Actions\Catalogue\SaveProduct;
use App\Enums\ProductCondition;
use App\Models\Auction;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithCompanies;
use Tests\TestCase;

/** Products and their pictures, as the seller's team keeps them. */
class ProductTest extends TestCase
{
    use InteractsWithCompanies;
    use RefreshDatabase;

    private Company $company;

    private User $member;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        [$this->company] = $this->company($this->plan());
        $this->member = User::factory()->companyMember($this->company)->create();
        $this->category = Category::factory()->create();
    }

    /** @param  list<UploadedFile|string>  $images */
    private function save(?Product $product = null, array $data = [], array $images = [], ?User $user = null): Product
    {
        return app(SaveProduct::class)->handle($user ?? $this->member, $this->company, $product, $data + [
            'name' => 'Brass ship lantern',
            'category_id' => $this->category->id,
            'description' => 'A working lantern from the 1920s.',
            'condition' => ProductCondition::Used->value,
            'attributes' => ['Height' => '32 cm', ' ' => 'dropped', 'Maker' => ''],
        ], $images);
    }

    public function test_a_team_member_adds_a_product_with_pictures_stored_full_size_and_as_thumbnails(): void
    {
        $product = $this->save(images: [
            UploadedFile::fake()->image('front.jpg', 2400, 1800),
            UploadedFile::fake()->image('back.png', 300, 300),
        ]);

        $this->assertSame($this->company->id, $product->company_id);
        $this->assertSame($this->member->id, $product->created_by);
        $this->assertSame(['Height' => '32 cm'], $product->getAttribute('attributes'));

        $images = $product->images()->get();
        $this->assertCount(2, $images);
        $this->assertSame([0, 1], $images->pluck('sort_order')->all());

        foreach ($images as $image) {
            Storage::disk('public')->assertExists([$image->path, (string) $image->thumbnail_path]);
            $this->assertStringEndsWith('.webp', $image->path);
        }

        // Scaled down to the configured sizes.
        [$width] = getimagesizefromstring((string) Storage::disk('public')->get($images[0]->path)) ?: [0];
        [$thumbWidth] = getimagesizefromstring((string) Storage::disk('public')->get((string) $images[0]->thumbnail_path)) ?: [0];
        $this->assertSame(1600, $width);
        $this->assertSame(640, $thumbWidth);
    }

    public function test_pictures_are_reordered_kept_and_removed_on_update(): void
    {
        $product = $this->save(images: [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ]);
        [$first, $second] = $product->images()->get()->all();

        $this->save($product, ['name' => 'Ship lantern'], [
            $second->path,
            UploadedFile::fake()->image('c.jpg'),
        ]);

        $images = $product->images()->get();
        $this->assertSame('Ship lantern', $product->refresh()->name);
        $this->assertCount(2, $images);
        $this->assertSame($second->id, $images[0]->id);
        $this->assertSame(0, $images[0]->sort_order);
        $this->assertSame($second->id, $product->coverImage()->value('id'));
        $this->assertDatabaseMissing('product_images', ['id' => $first->id]);
        Storage::disk('public')->assertMissing([$first->path, (string) $first->thumbnail_path]);
    }

    public function test_a_path_that_is_not_one_of_the_products_pictures_is_refused(): void
    {
        $product = $this->save();

        $this->expectException(ValidationException::class);
        $this->save($product, images: ['avatars/someone-else.webp']);
    }

    public function test_a_file_that_is_not_a_picture_is_refused_and_nothing_is_kept(): void
    {
        try {
            $this->save(images: [UploadedFile::fake()->image('ok.jpg'), UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]);
            $this->fail('A PDF was accepted as a picture.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('images', $e->errors());
        }

        $this->assertSame(0, Product::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_too_many_pictures_are_refused(): void
    {
        config(['auction.listing.max_images' => 2]);

        $this->expectException(ValidationException::class);
        $this->save(images: array_map(fn () => UploadedFile::fake()->image('p.jpg'), range(1, 3)));
    }

    public function test_a_product_is_filed_under_a_sub_category_when_its_parent_has_some(): void
    {
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);

        try {
            $this->save(data: ['category_id' => $parent->id]);
            $this->fail('A parent category with children was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category_id', $e->errors());
        }

        $inactive = Category::factory()->create(['is_active' => false]);
        $this->expectException(ValidationException::class);
        $this->save(data: ['category_id' => $inactive->id]);
    }

    public function test_details_are_frozen_while_an_auction_of_the_product_is_in_review_or_approved(): void
    {
        $product = $this->save();
        Auction::factory()->pendingReview()->create(['company_id' => $this->company->id, 'product_id' => $product->id]);

        $this->assertTrue($product->isLocked());
        $this->assertFalse($this->member->can('update', $product));

        $this->expectException(AuthorizationException::class);
        $this->save($product, ['name' => 'Something else']);
    }

    public function test_another_company_cannot_change_or_delete_the_product(): void
    {
        $product = $this->save();
        [, $stranger] = $this->company($this->plan());

        $this->assertFalse($stranger->can('view', $product));

        $this->expectException(AuthorizationException::class);
        app(DeleteProduct::class)->handle($stranger, $product);
    }

    public function test_a_product_is_deleted_only_when_none_of_its_auctions_is_open(): void
    {
        $product = $this->save();
        $auction = Auction::factory()->create(['company_id' => $this->company->id, 'product_id' => $product->id]);

        try {
            app(DeleteProduct::class)->handle($this->member, $product);
            $this->fail('A product with a draft auction was deleted.');
        } catch (ValidationException) {
            $this->assertNotSoftDeleted($product);
        }

        $auction->forceFill(['status' => 'cancelled'])->save();
        app(DeleteProduct::class)->handle($this->member, $product);

        $this->assertSoftDeleted($product);
    }
}
