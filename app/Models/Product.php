<?php

namespace App\Models;

use App\Enums\ProductCondition;
use Database\Factories\ProductFactory;
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
}
