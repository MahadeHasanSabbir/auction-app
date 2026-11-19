<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\AuctionCloseReason;
use App\Enums\AuctionStatus;
use App\Enums\OrderStatus;
use App\Support\Money;
use Database\Factories\AuctionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class Auction extends Model
{
    /** @use HasFactory<AuctionFactory> */
    use HasFactory;

    /**
     * Only what a seller fills in. Status, the approved schedule, the cached
     * bidding state and the outcome are written by actions.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id', 'title', 'starting_price', 'reserve_price', 'buy_now_price', 'min_increment',
        'proposed_starts_at', 'proposed_ends_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => AuctionStatus::Draft->value,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AuctionStatus::class,
            'close_reason' => AuctionCloseReason::class,
            'starting_price' => MoneyCast::class,
            'reserve_price' => MoneyCast::class,
            'buy_now_price' => MoneyCast::class,
            'min_increment' => MoneyCast::class,
            'current_price' => MoneyCast::class,
            'final_price' => MoneyCast::class,
            'reserve_met' => 'boolean',
            'fee_snapshot' => 'array',
            'proposed_starts_at' => 'datetime',
            'proposed_ends_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'original_ends_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Until the first bid the current price is the starting price.
        static::creating(function (Auction $auction): void {
            if (! array_key_exists('current_price', $auction->getAttributes())) {
                $auction->current_price = $auction->starting_price;
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    /** @return BelongsTo<User, $this> */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    /** @return HasMany<Bid, $this> */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    /** @return HasMany<AutoBid, $this> */
    public function autoBids(): HasMany
    {
        return $this->hasMany(AutoBid::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'watchlists')->withPivot('created_at');
    }

    /** @return HasMany<WalletHold, $this> */
    public function holds(): HasMany
    {
        return $this->hasMany(WalletHold::class);
    }

    /** @return HasOne<Order, $this> */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /**
     * Auctions the public may see: scheduled, live and finished ones.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleToPublic(Builder $query): void
    {
        $query->whereIn('status', array_filter(
            AuctionStatus::cases(),
            fn (AuctionStatus $status) => $status->isPublic(),
        ));
    }

    /**
     * Sold and the sale stands: an order cancelled before shipping (and
     * refunded) frees the product to be listed again.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSold(Builder $query): void
    {
        $query->where('status', AuctionStatus::Sold)
            ->whereDoesntHave('order', fn (Builder $order) => $order->where('status', OrderStatus::Cancelled));
    }

    /**
     * Everything an auction card shows, loaded in a fixed number of queries.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForCards(Builder $query): void
    {
        $query->with([
            'company:id,name,slug',
            'product:id,category_id',
            'product.category:id,name',
            'product.coverImage',
        ]);
    }

    /** Sets the next status, refusing any move AuctionStatus does not allow. */
    public function transitionTo(AuctionStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new LogicException("Auction {$this->id} cannot go from {$this->status->value} to {$next->value}.");
        }

        $this->status = $next;
    }

    public function isLive(): bool
    {
        return $this->status === AuctionStatus::Live;
    }

    /** The lowest amount the next bid must reach. */
    public function minimumNextBid(): Money
    {
        // No leader: no bids yet, or every bid was retracted.
        return $this->leader_id === null
            ? $this->starting_price
            : $this->current_price->plus($this->min_increment);
    }
}
