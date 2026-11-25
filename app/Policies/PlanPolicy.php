<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;

/** Plans and company agreements decide what the platform earns: the system owner alone manages them. */
class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->owner($user);
    }

    public function view(User $user, Plan $model): bool
    {
        return $this->owner($user);
    }

    public function create(User $user): bool
    {
        return $this->owner($user);
    }

    public function update(User $user, Plan $model): bool
    {
        return $this->owner($user);
    }

    /**
     * Only a plan nothing points at: no subscription (current or scheduled)
     * and no invoice, which keep the plan they were issued for.
     */
    public function delete(User $user, Plan $model): bool
    {
        return $this->owner($user)
            && ! Subscription::query()->where('plan_id', $model->id)->orWhere('pending_plan_id', $model->id)->exists()
            && ! SubscriptionInvoice::query()->where('plan_id', $model->id)->exists();
    }

    private function owner(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::SystemOwner;
    }
}
