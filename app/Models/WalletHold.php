<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\HoldStatus;
use App\Enums\HoldType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money set aside in a wallet for a leading bid or a pending withdrawal.
 * The database allows one active bid hold per auction.
 */
class WalletHold extends Model
{
    /** @var list<string> */
    protected $fillable = ['wallet_id', 'type', 'status', 'amount', 'auction_id', 'withdrawal_request_id', 'released_at'];

    /** The generated column that backs the one-active-hold-per-auction index. */
    protected $hidden = ['active_bid_auction_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => HoldType::class,
            'status' => HoldStatus::class,
            'amount' => MoneyCast::class,
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<Auction, $this> */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /** @return BelongsTo<WithdrawalRequest, $this> */
    public function withdrawalRequest(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', HoldStatus::Active);
    }
}
