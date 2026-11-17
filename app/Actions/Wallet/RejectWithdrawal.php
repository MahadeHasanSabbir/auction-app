<?php

namespace App\Actions\Wallet;

use App\Actions\Wallet\Concerns\ChangesWithdrawalStatus;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Ledger\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Staff turn a pending or approved withdrawal down, with a reason the requester sees; the hold is released. */
final class RejectWithdrawal
{
    use ChangesWithdrawalStatus;

    public function __construct(private readonly Ledger $ledger) {}

    public function handle(User $staff, WithdrawalRequest $withdrawal, string $reason): WithdrawalRequest
    {
        Gate::forUser($staff)->authorize('review', $withdrawal);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give a reason of up to 500 characters.')]);
        }

        return DB::transaction(function () use ($staff, $withdrawal, $reason): WithdrawalRequest {
            $locked = $this->lockFor($withdrawal, WithdrawalStatus::Rejected);

            $this->ledger->release($this->activeHold($locked));

            $locked->forceFill([
                'status' => WithdrawalStatus::Rejected,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->logReview($staff, $locked, 'rejected', $reason);

            return $this->syncFrom($withdrawal, $locked);
        });
    }
}
