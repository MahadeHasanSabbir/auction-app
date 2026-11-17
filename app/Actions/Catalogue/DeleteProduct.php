<?php

namespace App\Actions\Catalogue;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Removes a product from the company's list. It is soft-deleted, pictures
 * included, because finished auctions and orders still point at it.
 */
final class DeleteProduct
{
    public function handle(User $user, Product $product): void
    {
        Gate::forUser($user)->authorize('delete', $product);

        DB::transaction(function () use ($product): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($locked->hasOpenAuction()) {
                throw ValidationException::withMessages(['product' => __('Cancel or finish this product’s auction first.')]);
            }

            $locked->delete();
        });
    }
}
