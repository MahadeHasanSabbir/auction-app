<?php

namespace App\Actions\Team;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Withdraws an invitation that has not been accepted; its seat is free again. */
final class RevokeInvitation
{
    public function handle(User $user, Company $company, CompanyInvitation $invitation): void
    {
        Gate::forUser($user)->authorize('manageTeam', $company);

        abort_unless($invitation->company_id === $company->id, 404);

        if ($invitation->accepted_at === null && $invitation->revoked_at === null) {
            $invitation->forceFill(['revoked_at' => now()])->save();
        }
    }
}
