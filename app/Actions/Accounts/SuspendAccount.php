<?php

namespace App\Actions\Accounts;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Platform staff suspend a buyer (the system owner also a staff account):
 * signed out everywhere, trusted browsers forgotten, and sign-in refused like
 * a wrong password. Bids already placed stand; the wallet and orders stay.
 * Logged to the `admin` activity log with the reason.
 */
final class SuspendAccount
{
    use LogsAdminActions;

    public function handle(User $by, User $account, string $reason): User
    {
        Gate::forUser($by)->authorize('suspend', $account);

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give the reason in a few words.')]);
        }

        DB::transaction(function () use ($by, $account, $reason): void {
            $this->suspend($account, $reason);
            $this->record($by, $account, 'suspended', ['reason' => $reason]);
        });

        return $account;
    }

    /** The system suspends an account that reached the strike limit. */
    public function automatically(User $account, string $reason): void
    {
        DB::transaction(function () use ($account, $reason): void {
            $this->suspend($account, mb_substr($reason, 0, 500));

            activity('admin')->performedOn($account)->event('suspended')
                ->withProperties(['reason' => $reason, 'automatic' => true])
                ->log("suspended User {$account->id}");
        });
    }

    private function suspend(User $account, string $reason): void
    {
        // A new remember token kills every "remember me" cookie as well.
        $account->forceFill(['is_active' => false, 'suspension_reason' => $reason, 'remember_token' => Str::random(60)])->save();
        $account->trustedDevices()->delete();
        DB::table('sessions')->where('user_id', $account->id)->delete();
    }
}
