<?php

namespace App\Actions\Accounts;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * The system owner gives a staff member a new temporary password: their
 * sessions and trusted browsers end, and they must change it at the next
 * sign-in.
 */
final class ResetStaffPassword
{
    use LogsAdminActions;

    public function handle(User $owner, User $staff, string $password): User
    {
        Gate::forUser($owner)->authorize('manageStaff', User::class);

        abort_unless($staff->role === UserRole::PlatformStaff, 404);

        Validator::make(['password' => $password], ['password' => ['required', 'string', Password::defaults()]])->validate();

        DB::transaction(function () use ($owner, $staff, $password): void {
            $staff->forceFill(['password' => $password, 'password_change_required' => true])->save();
            $staff->trustedDevices()->delete();
            DB::table('sessions')->where('user_id', $staff->id)->delete();

            $this->record($owner, $staff, 'password_reset');
        });

        return $staff;
    }
}
