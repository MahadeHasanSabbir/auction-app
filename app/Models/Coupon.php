<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CouponDiscountType;
use App\Enums\CouponTarget;
use App\Support\Money;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount code. Platform coupons (company_id null) are for plan
 * invoices, the featured-listing fee or wallet bonus credit; a seller
 * coupon takes money off that company's orders, at the company's cost.
 * Checked and redeemed by App\Services\Coupons\Coupons.
 *
 * value is basis points for a percentage, minor units for a fixed amount.
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    /**
     * company_id, target, created_by and used_count are set by the code
     * that may.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code', 'discount_type', 'value', 'max_discount', 'min_amount', 'max_uses',
        'per_user_limit', 'starts_at', 'ends_at', 'is_active', 'description',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'per_user_limit' => 1,
        'used_count' => 0,
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target' => CouponTarget::class,
            'discount_type' => CouponDiscountType::class,
            'value' => 'integer',
            'max_discount' => MoneyCast::class,
            'min_amount' => MoneyCast::class,
            'max_uses' => 'integer',
            'per_user_limit' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $coupon): void {
            $coupon->code = self::normalise((string) $coupon->code);
        });
    }

    public static function normalise(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /** "10%" or "৳500.00", for lists. */
    public function valueLabel(): string
    {
        if ($this->discount_type === CouponDiscountType::Percent) {
            return rtrim(rtrim(number_format($this->value / 100, 2), '0'), '.').'%';
        }

        return Money::minor($this->value)->format();
    }

    /**
     * What the coupon takes off $amount: a percentage (capped at
     * max_discount) or a fixed amount, never more than $amount.
     */
    public function discountOn(Money $amount): Money
    {
        $discount = $this->discount_type === CouponDiscountType::Percent
            ? $amount->basisPointsFloor($this->value)
            : Money::minor($this->value, $amount->currency);

        if ($this->max_discount !== null && $discount->greaterThan($this->max_discount)) {
            $discount = $this->max_discount;
        }

        return $discount->greaterThan($amount) ? $amount : $discount;
    }
}
