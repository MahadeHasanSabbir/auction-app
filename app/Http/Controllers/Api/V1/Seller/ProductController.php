<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Actions\Catalogue\DeleteProduct;
use App\Actions\Catalogue\SaveProduct;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\V1\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;

/**
 * @group Seller: products
 *
 * The company's catalogue. Seller accounts only; the whole team may keep products.
 * Send pictures as multipart form data (`images[]` files, JPG, PNG or WebP, up to 8 MB each).
 *
 * @authenticated
 */
class ProductController extends ApiController
{
    /**
     * List products
     *
     * @queryParam page integer Page number. Example: 1
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->where('company_id', $this->company($request)->id)
            ->with(['category:id,name', 'images'])
            ->latest('id')
            ->paginate($this->perPage($request));

        return ProductResource::collection($products);
    }

    /**
     * Show a product
     *
     * @urlParam product integer required The product id. Example: 7
     */
    public function show(Request $request, int $product): ProductResource
    {
        return new ProductResource($this->find($request, $product));
    }

    /**
     * Create a product
     *
     * @bodyParam name string required At least 3 characters. Example: Brass table clock
     * @bodyParam category_id integer required A category that accepts products. Example: 4
     * @bodyParam description string Example: Working order, 1950s.
     * @bodyParam condition string A product condition code (for example new, used_good). Example: used_good
     * @bodyParam attributes object Free key/value details. Example: {"material":"brass"}
     * @bodyParam images file[] Pictures, the first is the cover.
     */
    public function store(Request $request, SaveProduct $save): JsonResponse
    {
        $company = $this->company($request);

        $product = $save->handle($this->user($request), $company, null, $request->only(['name', 'category_id', 'description', 'condition', 'attributes']), $this->newImages($request));

        return (new ProductResource($this->find($request, $product->id)))->response()->setStatusCode(201);
    }

    /**
     * Update a product
     *
     * Not possible while an auction of it is under review or approved. List the pictures to keep in
     * `existing_images` (the `path` values from the product, in the order you want); new files in `images`
     * follow them. A picture you leave out is removed.
     *
     * @urlParam product integer required The product id. Example: 7
     *
     * @bodyParam name string required At least 3 characters. Example: Brass table clock
     * @bodyParam category_id integer required A category that accepts products. Example: 4
     * @bodyParam description string Example: Working order, 1950s.
     * @bodyParam condition string A product condition code. Example: used_good
     * @bodyParam attributes object Free key/value details.
     * @bodyParam existing_images string[] Paths of the pictures to keep, in order.
     * @bodyParam images file[] New pictures, added after the kept ones.
     */
    public function update(Request $request, int $product, SaveProduct $save): ProductResource
    {
        $model = $this->find($request, $product);
        $kept = array_values(array_filter((array) $request->input('existing_images', []), 'is_string'));

        $save->handle(
            $this->user($request),
            $this->company($request),
            $model,
            $request->only(['name', 'category_id', 'description', 'condition', 'attributes']),
            [...$kept, ...$this->newImages($request)],
        );

        return new ProductResource($this->find($request, $model->id));
    }

    /**
     * Delete a product
     *
     * Refused while an auction of it is open.
     *
     * @urlParam product integer required The product id. Example: 7
     */
    public function destroy(Request $request, int $product, DeleteProduct $delete): JsonResponse
    {
        $delete->handle($this->user($request), $this->find($request, $product));

        return response()->json(['message' => __('Product deleted.')]);
    }

    /** One of this company's products, or 404. */
    private function find(Request $request, int $id): Product
    {
        return Product::query()
            ->where('company_id', $this->company($request)->id)
            ->with(['category:id,name', 'images'])
            ->findOrFail($id);
    }

    /** @return list<UploadedFile> */
    private function newImages(Request $request): array
    {
        $files = $request->file('images', []);

        return array_values(is_array($files) ? $files : [$files]);
    }
}
