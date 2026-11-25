<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'plan_id', 'status', 'billing_interval', 'price', 'pending_plan_id', 'pending_billing_interval',
        'max_users_override', 'max_active_auctions_override',
        'trial_ends_at', 'current_period_start', 'current_period_end', 'grace_ends_at',
        'cancelled_at', 'ended_at', 'gateway', 'gateway_customer_id', 'gateway_payment_method_id',
    ];

    /** @var list<string> */
    protected $hidden = ['gateway_customer_id', 'gateway_payment_method_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_interval' => BillingInterval::class,
            'pending_billing_interval' => BillingInterval::class,
            'price' => MoneyCast::class,
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    /** @return HasMany<SubscriptionInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /** The company may create listings and invite members. */
    public function allowsSelling(): bool
    {
        return $this->status->allowsSelling();
    }

    /** A trial was started once, even if it has ended; each company gets one. */
    public function hadTrial(): bool
    {
        return $this->trial_ends_at !== null;
    }

    /** Seats the company may fill: the custom limit if one was agreed, else the plan limit. */
    public function maxUsers(): int
    {
        return $this->max_users_override ?? $this->plan->max_users;
    }

    /** Null means unlimited. */
    public function maxActiveAuctions(): ?int
    {
        return $this->max_active_auctions_override ?? $this->plan->max_active_auctions;
    }
}
