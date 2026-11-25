<?php

namespace App\Actions\Api;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Staff cut off an API token (a leaked or abused one). The app using it gets
 * a 401 from its next request. Logged to the `admin` activity log with the
 * reason, on the account the token belonged to.
 */
final class RevokeApiToken
{
    public function handle(User $staff, ApiToken $token, string $reason): void
    {
        Gate::forUser($staff)->authorize('revoke', $token);

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give the reason in a few words.')]);
        }

        DB::transaction(function () use ($staff, $token, $reason): void {
            $owner = $token->tokenable_type === 'user' ? User::withTrashed()->find($token->tokenable_id) : null;

            $activity = activity('admin')
                ->causedBy($staff)
                ->event('api_token_revoked')
                ->withProperties(['token' => $token->name, 'reason' => $reason, 'user_id' => $token->tokenable_id]);

            if ($owner !== null) {
                $activity->performedOn($owner);
            }

            $activity->log("revoked API token \"{$token->name}\"");

            $token->delete();
        });
    }
}
