<?php

namespace App\Filament\Seller\Resources\Products\Pages\Concerns;

use App\Actions\Catalogue\SaveProduct;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Filament\Support\FormErrors;
use App\Models\Company;
use App\Models\Product;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Create and edit both hand the form to SaveProduct. */
trait SavesProduct
{
    /** @param  array<string, mixed>  $data */
    protected function saveProduct(?Product $product, array $data): Product
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Company, 404);

        // The upload field holds new files and the paths of kept pictures, in order.
        $images = array_values(array_filter(
            (array) ($data['images'] ?? []),
            fn (mixed $image) => is_string($image) || $image instanceof UploadedFile,
        ));

        try {
            return app(SaveProduct::class)->handle(ProductResource::user(), $tenant, $product, $data, $images);
        } catch (ValidationException $e) {
            FormErrors::rethrow($e, ['name', 'category_id', 'condition', 'description', 'attributes', 'images']);
        }
    }
}
