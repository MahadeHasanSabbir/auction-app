<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer's complaint about an order. The database allows one open dispute
 * per order; while it is open, escrow is not released.
 */
class Dispute extends Model
{
    /** @var list<string> */
    protected $fillable = ['reason', 'description'];

    /** The generated column that backs the one-open-dispute-per-order index. */
    protected $hidden = ['open_order_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'resolution' => DisputeResolution::class,
            'refund_amount' => MoneyCast::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
