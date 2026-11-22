<?php

namespace App\Models;

use App\Support\AuctionFilters;
use Database\Factories\SavedSearchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer's saved filters on the auction list. While alerts are on, a newly
 * approved auction that matches tells the buyer (AlertSavedSearches).
 */
class SavedSearch extends Model
{
    /** @use HasFactory<SavedSearchFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'filters', 'alerts'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'alerts' => 'boolean',
            'last_alerted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function criteria(): AuctionFilters
    {
        /** @var array<string, mixed> $filters */
        $filters = $this->filters ?? [];

        return AuctionFilters::fromArray($filters);
    }
}
