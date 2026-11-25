<?php

namespace App\Actions\Team;

use App\Enums\UserRole;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns an emailed invitation into a team member's account. The email is the
 * address the invitation was sent to, and it counts as verified because the
 * link reached it. The member then signs in the usual way.
 */
final class AcceptInvitation
{
    /** The invitation for a token from a link, if it can still be accepted. */
    public function find(string $token): ?CompanyInvitation
    {
        return CompanyInvitation::query()
            ->with('company')
            ->where('token_hash', hash('sha256', $token))
            ->pending()
            ->first();
    }

    /**
     * @param  array{name: string, password: string, phone?: string|null}  $data
     *
     * @throws ValidationException when the link is no longer good
     */
    public function handle(string $token, array $data): User
    {
        return DB::transaction(function () use ($token, $data): User {
            $invitation = CompanyInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->pending()
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                throw ValidationException::withMessages(['token' => __('This invitation is no longer valid. Ask the company owner for a new one.')]);
            }

            if (User::withTrashed()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['token' => __('An account with this address already exists.')]);
            }

            $user = new User([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
            ]);
            $user->role = UserRole::CompanyMember;
            $user->company_id = $invitation->company_id;
            $user->email_verified_at = now();
            $user->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });
    }
}
