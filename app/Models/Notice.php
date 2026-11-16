<?php

namespace App\Models;

use App\Enums\NoticeAudience;
use Database\Factories\NoticeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notice extends Model
{
    /** @use HasFactory<NoticeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['title', 'body', 'audience', 'published_at', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'audience' => NoticeAudience::class,
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Notices that are published and not yet expired.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
