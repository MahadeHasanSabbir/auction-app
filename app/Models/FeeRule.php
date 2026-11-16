<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\FeeChargeType;
use App\Enums\FeeUseCase;
use Carbon\CarbonInterface;
use Database\Factories\FeeRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A charge set by platform staff: percent of the amount plus a fixed amount,
 * clamped between min and max.
 */
class FeeRule extends Model
{
    /** @use HasFactory<FeeRuleFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'use_case', 'charge_type', 'user_type_id', 'gateway', 'percent_bps', 'fixed_amount',
        'min_amount', 'max_amount', 'effective_from', 'effective_until', 'is_active', 'note', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'use_case' => FeeUseCase::class,
            'charge_type' => FeeChargeType::class,
            'fixed_amount' => MoneyCast::class,
            'min_amount' => MoneyCast::class,
            'max_amount' => MoneyCast::class,
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<UserType, $this> */
    public function userType(): BelongsTo
    {
        return $this->belongsTo(UserType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Active rules in force at the given moment (now by default).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeEffectiveAt(Builder $query, ?CarbonInterface $at = null): void
    {
        $at ??= now();

        $query->where('is_active', true)
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at));
    }
}
