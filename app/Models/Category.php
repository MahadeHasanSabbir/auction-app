<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['parent_id', 'name', 'slug', 'description', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Active categories a product can be filed under, for a select field:
     * sub-categories grouped by their parent, and top-level categories that
     * have no sub-categories of their own.
     *
     * @return array<string, string|array<int, string>>
     */
    public static function selectOptions(): array
    {
        $all = self::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'parent_id', 'name']);
        $children = $all->whereNotNull('parent_id')->groupBy('parent_id');
        $options = [];

        foreach ($all->whereNull('parent_id') as $parent) {
            $group = $children->get($parent->id);

            if ($group === null) {
                $options[(string) $parent->id] = $parent->name;
            } else {
                $options[$parent->name] = $group->pluck('name', 'id')->all();
            }
        }

        return $options;
    }

    /** Whether a product may be filed here: active, and a leaf (or a parent without children). */
    public function acceptsProducts(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->parent_id !== null) {
            return self::query()->whereKey($this->parent_id)->where('is_active', true)->exists();
        }

        return ! $this->children()->where('is_active', true)->exists();
    }
}
