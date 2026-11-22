<?php

namespace App\Support;

use App\Models\Auction;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The search filters of the auction list (text, category, seller, price),
 * shared by the list page and saved searches: the list applies them to a
 * query, a saved-search alert checks one auction against them.
 */
final readonly class AuctionFilters
{
    public function __construct(
        public string $search = '',
        /** A category slug; a parent category includes its children. */
        public string $category = '',
        /** A company slug. */
        public string $company = '',
        /** Price bounds as typed, in major units ("1500" or "1500.50"). */
        public string $minPrice = '',
        public string $maxPrice = '',
    ) {}

    /** @param  array<string, mixed>  $values */
    public static function fromArray(array $values): self
    {
        $text = fn (string $key, int $max) => mb_substr(trim(is_scalar($values[$key] ?? null) ? (string) $values[$key] : ''), 0, $max);

        return new self(
            search: $text('q', 100),
            category: $text('category', 100),
            company: $text('company', 100),
            minPrice: $text('min', 20),
            maxPrice: $text('max', 20),
        );
    }

    /**
     * The non-empty filters, under the list page's query-string names.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'q' => $this->search,
            'category' => $this->category,
            'company' => $this->company,
            'min' => $this->minPrice,
            'max' => $this->maxPrice,
        ], fn (string $value) => $value !== '');
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /** The auction list showing these filters. */
    public function url(): string
    {
        return route('auctions.index', $this->toArray());
    }

    /**
     * @param  Builder<Auction>  $query
     * @return Builder<Auction>
     */
    public function apply(Builder $query): Builder
    {
        $search = trim($this->search);

        if ($search !== '') {
            // Typed % and _ are matched literally, not as wildcards.
            $like = '%'.addcslashes($search, '\\%_').'%';

            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', $like)
                ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', $like)));
        }

        if ($this->category !== '') {
            $ids = Category::query()
                ->where('slug', $this->category)
                ->orWhereHas('parent', fn (Builder $parent) => $parent->where('slug', $this->category))
                ->pluck('id');

            $query->whereHas('product', fn (Builder $product) => $product->whereIn('category_id', $ids));
        }

        if ($this->company !== '') {
            $query->whereHas('company', fn (Builder $company) => $company->where('slug', $this->company));
        }

        if (($min = self::price($this->minPrice)) !== null) {
            $query->where('current_price', '>=', $min->amount);
        }

        if (($max = self::price($this->maxPrice)) !== null) {
            $query->where('current_price', '<=', $max->amount);
        }

        return $query;
    }

    /**
     * Whether one auction passes the filters, without a query. The auction
     * needs product.category.parent and company loaded.
     */
    public function matches(Auction $auction): bool
    {
        $product = $auction->product;
        $search = mb_strtolower(trim($this->search));

        if ($search !== '' && ! str_contains(mb_strtolower($auction->title), $search)
            && ! str_contains(mb_strtolower((string) $product?->name), $search)) {
            return false;
        }

        if ($this->category !== '') {
            $category = $product?->category;

            if ($category === null || ($category->slug !== $this->category && $category->parent?->slug !== $this->category)) {
                return false;
            }
        }

        if ($this->company !== '' && $auction->company?->slug !== $this->company) {
            return false;
        }

        if (($min = self::price($this->minPrice)) !== null && $auction->current_price->lessThan($min)) {
            return false;
        }

        if (($max = self::price($this->maxPrice)) !== null && $auction->current_price->greaterThan($max)) {
            return false;
        }

        return true;
    }

    /**
     * A short description for lists and alerts: "“lamp” · Lighting · under ৳5,000.00".
     *
     * @param  array<string, string>  $categoryNames  slug => name
     * @param  array<string, string>  $companyNames  slug => name
     */
    public function summary(array $categoryNames = [], array $companyNames = []): string
    {
        $min = self::price($this->minPrice);
        $max = self::price($this->maxPrice);

        $parts = array_filter([
            $this->search !== '' ? '“'.$this->search.'”' : '',
            $this->category !== '' ? ($categoryNames[$this->category] ?? $this->category) : '',
            $this->company !== '' ? ($companyNames[$this->company] ?? $this->company) : '',
            match (true) {
                $min !== null && $max !== null => __(':min to :max', ['min' => $min->format(), 'max' => $max->format()]),
                $min !== null => __('from :min', ['min' => $min->format()]),
                $max !== null => __('up to :max', ['max' => $max->format()]),
                default => '',
            },
        ]);

        return $parts === [] ? __('All auctions') : implode(' · ', $parts);
    }

    /** A typed price as Money, or null when empty or not a valid amount. */
    public static function price(string $typed): ?Money
    {
        $typed = trim(str_replace(',', '', $typed));

        if ($typed === '') {
            return null;
        }

        try {
            $money = Money::parse($typed);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $money->isNegative() ? null : $money;
    }
}
