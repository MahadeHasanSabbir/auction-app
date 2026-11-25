<?php

namespace App\Actions\Wallet\Concerns;

use App\Enums\HoldStatus;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\WalletHold;
use App\Models\WithdrawalRequest;
use Illuminate\Validation\ValidationException;

/** The steps every withdrawal status change shares. */
trait ChangesWithdrawalStatus
{
    /**
     * Locks the request (the domain row comes first in the lock order) and
     * checks it may move to $next. Must run inside a DB transaction.
     */
    private function lockFor(WithdrawalRequest $withdrawal, WithdrawalStatus $next): WithdrawalRequest
    {
        $locked = WithdrawalRequest::query()->lockForUpdate()->findOrFail($withdrawal->id);

        if (! $locked->status->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => __('This withdrawal is already :status.', ['status' => mb_strtolower($locked->status->label())]),
            ]);
        }

        return $locked;
    }

    /** The hold that keeps an open request's money aside. */
    private function activeHold(WithdrawalRequest $withdrawal): WalletHold
    {
        return WalletHold::query()
            ->where('withdrawal_request_id', $withdrawal->id)
            ->where('status', HoldStatus::Active)
            ->sole();
    }

    /** Staff money actions are logged with who did it and why. */
    private function logReview(User $staff, WithdrawalRequest $withdrawal, string $event, ?string $note = null): void
    {
        activity('money')
            ->causedBy($staff)
            ->performedOn($withdrawal)
            ->event($event)
            ->withProperties(array_filter([
                'amount' => $withdrawal->amount->amount,
                'currency' => $withdrawal->currency,
                'note' => $note,
            ], fn (mixed $value) => $value !== null))
            ->log("Withdrawal {$withdrawal->id} {$event}");
    }

    /** Copies the locked row's state onto the caller's model. */
    private function syncFrom(WithdrawalRequest $withdrawal, WithdrawalRequest $locked): WithdrawalRequest
    {
        $withdrawal->setRawAttributes($locked->getAttributes(), true);

        return $withdrawal;
    }
}
