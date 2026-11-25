<?php

namespace App\Models;

use App\Enums\LedgerTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement of money. Append-only; its entries always sum to zero.
 */
class LedgerTransaction extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['type', 'idempotency_key', 'reference_type', 'reference_id', 'description', 'meta', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LedgerTransactionType::class,
            'meta' => 'array',
        ];
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * What the money moved for: a deposit, an order, a withdrawal request, …
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
