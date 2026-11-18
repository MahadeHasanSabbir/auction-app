<?php

namespace App\Actions\Fees;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Models\FeeRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Stops a fee rule from now on. The rule itself is kept unchanged, because
 * past deposits and withdrawals point at it. A rule that has not started yet
 * is switched off instead.
 */
final class EndFeeRule
{
    use LogsAdminActions;

    public function handle(User $staff, FeeRule $rule): FeeRule
    {
        Gate::forUser($staff)->authorize('end', $rule);

        DB::transaction(function () use ($staff, $rule): void {
            if ($rule->effective_from->isFuture()) {
                $rule->forceFill(['is_active' => false])->save();
            } else {
                $rule->forceFill(['effective_until' => now()])->save();
            }

            $this->record($staff, $rule, 'ended');
        });

        return $rule;
    }
}
