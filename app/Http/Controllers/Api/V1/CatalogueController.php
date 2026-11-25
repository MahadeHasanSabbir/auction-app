<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CompanyStatus;
use App\Models\Category;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * @group Catalogue
 *
 * Categories and seller pages, for filters and storefronts. No token needed.
 *
 * @unauthenticated
 */
class CatalogueController extends ApiController
{
    /**
     * List categories
     *
     * Active top-level categories with their active children.
     */
    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $categories->map(fn (Category $category) => [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'children' => $category->children->map(fn (Category $child) => [
                'id' => $child->id,
                'name' => $child->name,
                'slug' => $child->slug,
            ])->all(),
        ])->all()]);
    }

    /**
     * Show a seller
     *
     * The seller's public page. Contact details are never included.
     *
     * @urlParam company string required The seller's slug. Example: dhaka-antiques
     */
    public function seller(Company $company): JsonResponse
    {
        abort_if($company->status === CompanyStatus::Pending, 404);

        $disk = Storage::disk('public');

        return response()->json(['data' => [
            'id' => $company->id,
            'name' => $company->name,
            'slug' => $company->slug,
            'about' => $company->about,
            'logo_url' => $company->logo_path ? $disk->url($company->logo_path) : null,
            'banner_url' => $company->banner_path ? $disk->url($company->banner_path) : null,
            'website_url' => $company->website_url,
            'facebook_url' => $company->facebook_url,
            'verified' => $company->isVerified(),
            'rating' => $company->averageRating(),
            'rating_count' => $company->rating_count,
        ]]);
    }
}
