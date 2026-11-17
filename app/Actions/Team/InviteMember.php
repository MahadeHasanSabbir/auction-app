<?php

namespace App\Actions\Team;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Notifications\CompanyInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The company owner invites a colleague by email. An invitation holds a seat
 * until it is accepted, revoked or expires, so the plan's seat limit can
 * never be passed by inviting first and accepting later.
 *
 * The invited address must not have an account: a member joins by creating
 * one through the emailed link (AcceptInvitation).
 */
final class InviteMember
{
    /**
     * @throws ValidationException when the address or the seat count is not acceptable
     */
    public function handle(User $inviter, Company $company, string $email): CompanyInvitation
    {
        Gate::forUser($inviter)->authorize('manageTeam', $company);

        $email = Str::lower(trim($email));
        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->validate();

        [$invitation, $token] = DB::transaction(function () use ($inviter, $company, $email): array {
            // Serialises concurrent invitations of one company.
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $subscription = $company->subscription()->with('plan')->first();

            if ($subscription === null || ! $subscription->allowsSelling()) {
                throw ValidationException::withMessages(['email' => __('Choose a plan or pay your open invoice before inviting people.')]);
            }

            if (User::withTrashed()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => __('That address already has an account. Invite a work address that is new to :app.', ['app' => config('app.name')])]);
            }

            if ($company->invitations()->pending()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => __('That address has already been invited.')]);
            }

            // A scheduled downgrade must fit too, or it could not be applied.
            $seats = $subscription->maxUsers();

            if ($subscription->pending_plan_id !== null) {
                $seats = min($seats, (int) $subscription->pendingPlan()->value('max_users'));
            }

            if ($company->seatsUsed() >= $seats) {
                throw ValidationException::withMessages(['email' => __('Your plan has :seats seats and all are taken. Upgrade the plan or remove someone first.', ['seats' => $seats])]);
            }

            $token = Str::random(48);
            $invitation = $company->invitations()->create([
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays((int) config('auction.billing.invitation_days')),
            ]);

            return [$invitation, $token];
        });

        Notification::route('mail', $email)->notify(new CompanyInvitationNotification(
            $company,
            $inviter->name,
            route('invitations.show', ['token' => $token]),
            (int) config('auction.billing.invitation_days'),
        ));

        return $invitation;
    }
}
