<?php

namespace App\Actions\Wallet;

use App\Actions\Wallet\Concerns\ChangesWithdrawalStatus;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Staff accept a withdrawal for payout. The money stays on hold until it is paid. */
final class ApproveWithdrawal
{
    use ChangesWithdrawalStatus;

    public function handle(User $staff, WithdrawalRequest $withdrawal): WithdrawalRequest
    {
        Gate::forUser($staff)->authorize('review', $withdrawal);

        return DB::transaction(function () use ($staff, $withdrawal): WithdrawalRequest {
            $locked = $this->lockFor($withdrawal, WithdrawalStatus::Approved);

            $locked->forceFill([
                'status' => WithdrawalStatus::Approved,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
            ])->save();

            $this->logReview($staff, $locked, 'approved');

            return $this->syncFrom($withdrawal, $locked);
        });
    }
}
