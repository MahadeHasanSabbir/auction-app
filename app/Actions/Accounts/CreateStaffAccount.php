<?php

namespace App\Actions\Accounts;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The system owner adds a platform staff account with a temporary password,
 * which they hand over themselves. The address counts as verified; signing
 * in still needs the emailed code, and the admin panel asks for a new
 * password before anything else (`password_change_required`).
 */
final class CreateStaffAccount
{
    use LogsAdminActions;

    /** @param  array<string, mixed>  $input  name, email, password */
    public function handle(User $owner, array $input): User
    {
        Gate::forUser($owner)->authorize('manageStaff', User::class);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
        ])->validate();

        return DB::transaction(function () use ($owner, $data): User {
            $staff = new User([
                'name' => trim((string) $data['name']),
                'email' => mb_strtolower(trim((string) $data['email'])),
                'password' => (string) $data['password'],
            ]);
            $staff->forceFill([
                'role' => UserRole::PlatformStaff,
                'email_verified_at' => now(),
                'password_change_required' => true,
            ])->save();

            $this->record($owner, $staff, 'staff_created');

            return $staff;
        });
    }
}
