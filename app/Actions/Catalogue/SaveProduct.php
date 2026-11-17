<?php

namespace App\Actions\Catalogue;

use App\Enums\ProductCondition;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Creates or updates a company's product and its pictures.
 *
 * Pictures arrive in display order, each either a new upload or the path of
 * a picture the product already has; the first one is the cover. New uploads
 * are re-encoded through ImageStore (full size and a thumbnail) before the
 * transaction, pictures left out are deleted after it commits.
 */
final class SaveProduct
{
    public function __construct(private readonly ImageStore $images) {}

    /**
     * @param  array<string, mixed>  $data  name, category_id, description, condition, attributes
     * @param  list<UploadedFile|string>  $images
     */
    public function handle(User $user, Company $company, ?Product $product, array $data, array $images): Product
    {
        if ($product === null) {
            Gate::forUser($user)->authorize('create', Product::class);
            abort_unless($user->company_id === $company->id, 403);
        } else {
            Gate::forUser($user)->authorize('update', $product);
        }

        $data = $this->validate($data, $images, $product);

        // Re-encode new pictures first; undo them if anything below fails.
        $stored = [];

        try {
            foreach ($images as $index => $image) {
                if ($image instanceof UploadedFile) {
                    $stored[$index] = $this->store($image);
                }
            }

            [$saved, $removed] = DB::transaction(fn () => $this->persist($user, $company, $product, $data, $images, $stored));
        } catch (Throwable $e) {
            foreach ($stored as $paths) {
                $this->images->delete($paths['path']);
                $this->images->delete($paths['thumbnail_path']);
            }

            throw $e;
        }

        foreach ($removed as $image) {
            $this->images->delete($image->path);
            $this->images->delete($image->thumbnail_path);
        }

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile|string>  $images
     * @param  array<int, array{path: string, thumbnail_path: string}>  $stored
     * @return array{0: Product, 1: list<ProductImage>}
     */
    private function persist(User $user, Company $company, ?Product $product, array $data, array $images, array $stored): array
    {
        if ($product === null) {
            $product = new Product;
            $product->forceFill(['company_id' => $company->id, 'created_by' => $user->id]);
        } else {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($locked->isLocked()) {
                throw ValidationException::withMessages(['product' => __('This product is in an auction under review or approved, so it cannot be changed.')]);
            }

            $product = $locked;
        }

        $product->fill($data)->save();

        $existing = $product->images()->get()->keyBy('path');
        $kept = [];

        foreach ($images as $index => $image) {
            if (is_string($image)) {
                $row = $existing->get($image);
                $row?->forceFill(['sort_order' => $index])->save();
                $kept[] = $image;
            } else {
                $product->images()->create([...$stored[$index], 'sort_order' => $index]);
            }
        }

        // Not except(): it reads the dot in "x.webp" as a nested key.
        $removed = $existing->reject(fn (ProductImage $image) => in_array($image->path, $kept, true))->values();
        ProductImage::query()->whereKey($removed->modelKeys())->delete();

        return [$product, $removed->all()];
    }

    /** @return array{path: string, thumbnail_path: string} */
    private function store(UploadedFile $image): array
    {
        try {
            $path = $this->images->store($image, 'products', (int) config('auction.listing.image_max_side'));
            $thumbnail = $this->images->store($image, 'products/thumbs', (int) config('auction.listing.thumbnail_max_side'));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['images' => __('One of the pictures could not be read. Use a JPG, PNG or WebP file.')]);
        }

        return ['path' => $path, 'thumbnail_path' => $thumbnail];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile|string>  $images
     * @return array<string, mixed>
     */
    private function validate(array $data, array $images, ?Product $product): array
    {
        $known = $product?->images()->pluck('path')->all() ?? [];

        $validated = Validator::make([...$data, 'images' => $images], [
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'description' => ['nullable', 'string', 'max:10000'],
            'condition' => ['nullable', Rule::enum(ProductCondition::class)],
            'attributes' => ['nullable', 'array', 'max:20'],
            'attributes.*' => ['nullable', 'string', 'max:255'],
            'images' => ['array', 'max:'.(int) config('auction.listing.max_images')],
            'images.*' => [function (string $attribute, mixed $value, \Closure $fail) use ($known): void {
                if (is_string($value) ? ! in_array($value, $known, true) : ! $value instanceof UploadedFile) {
                    $fail(__('That picture is not part of this product.'));
                }
            }],
        ], [], [
            'category_id' => __('category'),
        ])->validate();

        foreach ($images as $image) {
            if ($image instanceof UploadedFile) {
                Validator::make(['images' => $image], ['images' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192']])->validate();
            }
        }

        if (! Category::query()->findOrFail($validated['category_id'])->acceptsProducts()) {
            throw ValidationException::withMessages(['category_id' => __('Choose one of the listed categories.')]);
        }

        $attributes = [];

        foreach ((array) ($validated['attributes'] ?? []) as $key => $value) {
            $key = mb_substr(trim((string) $key), 0, 60);
            $value = trim((string) $value);

            if ($key !== '' && $value !== '') {
                $attributes[$key] = $value;
            }
        }

        return [
            'name' => trim($validated['name']),
            'category_id' => (int) $validated['category_id'],
            'description' => filled($validated['description'] ?? null) ? trim($validated['description']) : null,
            'condition' => $validated['condition'] ?? null,
            'attributes' => $attributes === [] ? null : $attributes,
        ];
    }
}
