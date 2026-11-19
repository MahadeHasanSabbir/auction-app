<?php

namespace App\Actions\Trust;

use App\Actions\Companies\Concerns\LogsAdminActions;
use App\Models\Strike;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Staff take a strike back. A suspension it caused stays until staff
 * reactivate the account.
 */
final class RevokeStrike
{
    use LogsAdminActions;

    public function handle(User $staff, Strike $strike): Strike
    {
        Gate::forUser($staff)->authorize('revoke', $strike);

        return DB::transaction(function () use ($staff, $strike): Strike {
            $locked = Strike::query()->lockForUpdate()->findOrFail($strike->id);

            if ($locked->revoked_at === null) {
                $locked->forceFill(['revoked_at' => now(), 'revoked_by' => $staff->id])->save();
                $this->record($staff, $locked, 'revoked', ['user_id' => $locked->user_id]);
            }

            $strike->setRawAttributes($locked->getAttributes(), true);

            return $strike;
        });
    }
}
