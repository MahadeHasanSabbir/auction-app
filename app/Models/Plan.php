<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BillingInterval;
use App\Support\Money;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    use LogsActivity;

    /** Changes to what the platform charges go to the admin activity log. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('admin')->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @var list<string> */
    protected $fillable = [
        'name', 'slug', 'description', 'price_monthly', 'price_yearly', 'max_users',
        'max_active_auctions', 'trial_days', 'features', 'is_public', 'is_active', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_monthly' => MoneyCast::class,
            'price_yearly' => MoneyCast::class,
            'features' => 'array',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** The price of one period, or null when the plan is not sold for that interval. */
    public function priceFor(BillingInterval $interval): ?Money
    {
        return match ($interval) {
            BillingInterval::Monthly => $this->price_monthly,
            BillingInterval::Yearly => $this->price_yearly,
        };
    }

    /**
     * Cost per month in minor units whichever way it is billed, to tell an
     * upgrade from a downgrade.
     */
    public function monthlyRate(BillingInterval $interval): ?int
    {
        $price = $this->priceFor($interval);

        return $price === null ? null : intdiv($price->amount, $interval === BillingInterval::Yearly ? 12 : 1);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
