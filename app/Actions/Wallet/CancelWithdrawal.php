<?php

namespace App\Actions\Wallet;

use App\Actions\Wallet\Concerns\ChangesWithdrawalStatus;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Ledger\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** The requester takes back a withdrawal staff have not looked at yet; the hold is released. */
final class CancelWithdrawal
{
    use ChangesWithdrawalStatus;

    public function __construct(private readonly Ledger $ledger) {}

    public function handle(User $user, WithdrawalRequest $withdrawal): WithdrawalRequest
    {
        Gate::forUser($user)->authorize('cancel', $withdrawal);

        return DB::transaction(function () use ($withdrawal): WithdrawalRequest {
            $locked = $this->lockFor($withdrawal, WithdrawalStatus::Cancelled);

            $this->ledger->release($this->activeHold($locked));
            $locked->forceFill(['status' => WithdrawalStatus::Cancelled])->save();

            return $this->syncFrom($withdrawal, $locked);
        });
    }
}
