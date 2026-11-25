<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One authenticated API request. Written by LogApiRequest only; the route is
 * the pattern ("api/v1/orders/{order}"), so no id or query string is kept.
 */
class ApiRequest extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('auction.api.log_days')));
    }
}
