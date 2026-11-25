<?php

namespace App\Http\Resources\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A product in a seller's catalogue. Load images and category first.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'condition' => $this->condition?->value,
            'attributes' => $this->attributes ?? [],
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'name' => $this->category->name]),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                // The path is what a client sends back to keep a picture when it updates the product.
                'path' => $image->path,
                'url' => Storage::disk('public')->url($image->path),
                'thumbnail_url' => Storage::disk('public')->url($image->thumbnail_path),
            ])->all()),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
