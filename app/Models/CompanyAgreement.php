<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CommissionBasis;
use App\Enums\FeeUseCase;
use Carbon\CarbonInterface;
use Database\Factories\CompanyAgreementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyAgreement extends Model
{
    /** @use HasFactory<CompanyAgreementFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'use_case', 'basis', 'rate_bps', 'fixed_amount', 'min_amount', 'max_amount',
        'effective_from', 'effective_until', 'note', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'use_case' => FeeUseCase::class,
            'basis' => CommissionBasis::class,
            'fixed_amount' => MoneyCast::class,
            'min_amount' => MoneyCast::class,
            'max_amount' => MoneyCast::class,
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Agreements in force at the given moment (now by default).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeEffectiveAt(Builder $query, ?CarbonInterface $at = null): void
    {
        $at ??= now();

        $query->where('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at));
    }
}
