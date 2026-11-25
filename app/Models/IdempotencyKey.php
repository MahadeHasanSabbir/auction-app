<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('auction.retention.idempotency_days')));
    }

    /** @var list<string> */
    protected $fillable = ['user_id', 'key', 'action', 'response'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
