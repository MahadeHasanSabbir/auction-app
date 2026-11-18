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

    /**
     * Notices meant for this viewer: everyone's, plus the sellers' for a
     * seller account, the buyers' for buyers and visitors, all for staff.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeFor(Builder $query, ?User $viewer): void
    {
        $audiences = match (true) {
            $viewer?->isPlatform() ?? false => NoticeAudience::cases(),
            $viewer?->isSeller() ?? false => [NoticeAudience::All, NoticeAudience::Sellers],
            default => [NoticeAudience::All, NoticeAudience::Buyers],
        };

        $query->whereIn('audience', $audiences);
    }

    /** The newest current notice for the viewer, for the banner. */
    public static function bannerFor(?User $viewer): ?self
    {
        return self::query()->current()->for($viewer)->latest('published_at')->latest('id')->first();
    }
}
