<?php

namespace App\Actions\Accounts;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Lets a suspended account sign in again. Logged to the `admin` activity log. */
final class ReactivateAccount
{
    use LogsAdminActions;

    public function handle(User $by, User $account): User
    {
        Gate::forUser($by)->authorize('reactivate', $account);

        DB::transaction(function () use ($by, $account): void {
            $account->forceFill(['is_active' => true, 'suspension_reason' => null])->save();

            $this->record($by, $account, 'reactivated');
        });

        return $account;
    }
}
