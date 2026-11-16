<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

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

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
