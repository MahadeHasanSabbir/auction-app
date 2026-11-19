<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BidType;
use Database\Factories\BidFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: a bid is never deleted, and the only change is retracted_at,
 * set by App\Actions\Bidding\RetractBids. Retracted bids keep their seq
 * (and count in auctions.bid_count) but no longer stand.
 */
class Bid extends Model
{
    /** @use HasFactory<BidFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['auction_id', 'user_id', 'amount', 'type', 'seq'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'type' => BidType::class,
            'retracted_at' => 'datetime',
        ];
    }

    /**
     * Bids that were not retracted.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeStanding(Builder $query): void
    {
        $query->whereNull('retracted_at');
    }

    /** @return BelongsTo<Auction, $this> */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function bidder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
