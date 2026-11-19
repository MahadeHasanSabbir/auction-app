<?php

namespace App\Models;

use App\Enums\BlacklistType;
use Database\Factories\BlacklistEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email address, email domain or IP address that may not sign up or
 * sign in. Checked by App\Services\Trust\Blacklist.
 */
class BlacklistEntry extends Model
{
    /** @use HasFactory<BlacklistEntryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['type', 'value', 'reason', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => BlacklistType::class,
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            $entry->value = self::normalise($entry->type, (string) $entry->value);
        });
    }

    public static function normalise(BlacklistType $type, string $value): string
    {
        $value = mb_strtolower(trim($value));

        return $type === BlacklistType::Domain ? ltrim($value, '@') : $value;
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** @param  Builder<$this>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
