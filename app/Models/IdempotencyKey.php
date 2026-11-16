<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    public const UPDATED_AT = null;

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
