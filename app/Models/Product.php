<?php

namespace App\Models;

use App\Enums\AuctionStatus;
use App\Enums\ProductCondition;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    /**
     * company_id and created_by come from the signed-in member, not the form.
     *
     * @var list<string>
     */
    protected $fillable = ['category_id', 'name', 'description', 'condition', 'attributes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'condition' => ProductCondition::class,
            'attributes' => 'array',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasOne<ProductImage, $this> */
    public function coverImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany('sort_order', 'min');
    }

    /** @return HasMany<Auction, $this> */
    public function auctions(): HasMany
    {
        return $this->hasMany(Auction::class);
    }

    /**
     * One of its auctions is under review or approved, so its details cannot
     * change. A list can preload this as `is_locked` with withExists().
     */
    public function isLocked(): bool
    {
        if (array_key_exists('is_locked', $this->attributes)) {
            return (bool) $this->attributes['is_locked'];
        }

        return $this->auctions()->whereIn('status', AuctionStatus::active())->exists();
    }

    /** An auction of it that has not finished (a draft counts): a product is listed once at a time. */
    public function hasOpenAuction(?Auction $except = null): bool
    {
        return $this->auctions()
            ->whereNotIn('status', [AuctionStatus::Sold, AuctionStatus::Unsold, AuctionStatus::Cancelled])
            ->when($except, fn (Builder $query) => $query->whereKeyNot($except->id))
            ->exists();
    }

    /** It was sold, so it cannot be listed again. */
    public function isSold(): bool
    {
        return $this->auctions()->where('status', AuctionStatus::Sold)->exists();
    }
}
