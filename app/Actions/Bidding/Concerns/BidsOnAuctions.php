<?php

namespace App\Actions\Bidding\Concerns;

use App\Models\IdempotencyKey;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Money;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** What placing a bid and buying now share: amounts, rate limit, idempotency, the buyer's wallet. */
trait BidsOnAuctions
{
    private function amount(Money|string|null $value): Money
    {
        if ($value instanceof Money) {
            return $value;
        }

        $value = trim((string) $value);

        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) !== 1) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount such as 1500 or 1500.50.')]);
        }

        return Money::parse($value);
    }

    private function throttle(User $user): void
    {
        $key = 'bid:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('auction.bidding.bids_per_minute'))) {
            throw ValidationException::withMessages(['amount' => __('Too many bids at once. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * The answer given the first time this key was used, if it was.
     *
     * @return array<string, mixed>|null
     */
    private function remembered(User $user, ?string $key, string $action): ?array
    {
        if ($key === null) {
            return null;
        }

        $row = IdempotencyKey::query()->where('user_id', $user->id)->where('key', $key)->first();

        if ($row === null) {
            return null;
        }

        if ($row->action !== $action || ! is_array($row->response)) {
            throw ValidationException::withMessages(['amount' => __('This form was already sent. Reload the page and try again.')]);
        }

        return $row->response;
    }

    /**
     * Claims the key at the start of the transaction. A second submission
     * with the same key waits here for the first and then fails on the
     * unique index; the caller answers it with the stored response.
     */
    private function claim(User $user, ?string $key, string $action): ?IdempotencyKey
    {
        if ($key === null) {
            return null;
        }

        $row = new IdempotencyKey;
        $row->forceFill(['user_id' => $user->id, 'key' => mb_substr($key, 0, 64), 'action' => $action])->save();

        return $row;
    }

    private function walletOf(User $user): Wallet
    {
        return Wallet::query()->where('user_id', $user->id)->first()
            ?? throw ValidationException::withMessages(['amount' => __('Your account has no wallet. Contact support.')]);
    }
}
