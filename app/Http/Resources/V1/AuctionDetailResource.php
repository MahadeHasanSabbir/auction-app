<?php

namespace App\Http\Resources\V1;

use App\Models\Auction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * One auction: the card figures plus description, pictures and anti-sniping
 * state. Load product.images, product.category, company and winner first.
 *
 * @mixin Auction
 */
class AuctionDetailResource extends AuctionResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $category = $product?->category;

        return [
            ...parent::toArray($request),
            'description' => $product?->description,
            'condition' => $product?->condition?->value,
            'attributes' => $product->attributes ?? [],
            'images' => $product === null ? [] : $product->images->map(fn ($image) => [
                'url' => Storage::disk('public')->url($image->path),
                'thumbnail_url' => Storage::disk('public')->url($image->thumbnail_path),
                'alt' => $image->alt,
            ])->all(),
            'min_increment' => $this->min_increment,
            'extension_count' => $this->extension_count,
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'winner' => $this->whenLoaded('winner', fn () => $this->winner?->maskedName()),
            'category' => $category === null ? null : ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug],
        ];
    }
}
