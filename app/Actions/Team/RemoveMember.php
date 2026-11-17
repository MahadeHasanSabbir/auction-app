<?php

namespace App\Actions\Team;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The company owner removes a member. The account is soft-deleted (bids,
 * listings and ledger rows keep pointing at it), signed out everywhere and
 * its seat is free again. The owner cannot be removed.
 */
final class RemoveMember
{
    /** @throws ValidationException when the person cannot be removed */
    public function handle(User $owner, Company $company, User $member): void
    {
        Gate::forUser($owner)->authorize('manageTeam', $company);

        abort_unless($member->company_id === $company->id, 404);

        if ($member->role !== UserRole::CompanyMember) {
            throw ValidationException::withMessages(['member' => __('The company owner cannot be removed.')]);
        }

        DB::transaction(function () use ($member): void {
            $member->trustedDevices()->delete();
            DB::table('sessions')->where('user_id', $member->id)->delete();

            $member->delete();
        });
    }
}
