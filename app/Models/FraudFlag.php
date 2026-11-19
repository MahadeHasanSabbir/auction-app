<?php

namespace App\Models;

use App\Enums\FraudFlagKind;
use App\Enums\FraudFlagStatus;
use Database\Factories\FraudFlagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that looks wrong, waiting for platform staff: raised by
 * App\Services\Trust\SharedIpCheck, decided with ReviewFraudFlag.
 */
class FraudFlag extends Model
{
    /** @use HasFactory<FraudFlagFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => FraudFlagKind::class,
            'status' => FraudFlagStatus::class,
            'details' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<Auction, $this> */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
