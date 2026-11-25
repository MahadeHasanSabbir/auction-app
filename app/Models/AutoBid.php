<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Database\Factories\AutoBidFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoBid extends Model
{
    /** @use HasFactory<AutoBidFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['auction_id', 'user_id', 'max_amount', 'is_active'];

    /** A maximum is private to its owner. */
    protected $hidden = ['max_amount'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_amount' => MoneyCast::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Auction, $this> */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
