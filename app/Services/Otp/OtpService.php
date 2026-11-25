<?php

namespace App\Services\Otp;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Emailed one-time codes.
 *
 * A code is random digits, stored only as a hash, valid for a few minutes,
 * usable once, and dead after a handful of wrong guesses. A user has at most
 * one live code per purpose: sending a new one retires the previous one.
 */
final class OtpService
{
    /**
     * Create a code and email it to the user.
     *
     * @throws OtpThrottledException when asked again too soon or too often
     */
    public function send(User $user, OtpPurpose $purpose, ?string $ip = null): OtpCode
    {
        $retryAfter = $this->secondsUntilNextSend($user, $purpose);

        if ($retryAfter > 0) {
            throw new OtpThrottledException($retryAfter);
        }

        $plain = $this->generate();

        $code = DB::transaction(function () use ($user, $purpose, $plain, $ip): OtpCode {
            // Retire any earlier code; only the newest one can be used.
            $this->live($user, $purpose)->update(['consumed_at' => now()]);

            return OtpCode::query()->create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'code_hash' => Hash::make($plain),
                'sent_to' => $user->email,
                'expires_at' => now()->addMinutes((int) config('auction.otp.ttl_minutes')),
                'ip_address' => $ip,
            ]);
        });

        $user->notify(new OtpCodeNotification($plain, $purpose));

        // Local development only: lets the code page show the code, so the
        // flow can be tried without opening the mail log.
        if (app()->isLocal()) {
            session()->flash('otp.debug_code', $plain);
        }

        return $code;
    }

    /** Check a typed code. A correct code is consumed and cannot be used again. */
    public function verify(User $user, OtpPurpose $purpose, string $input): OtpResult
    {
        return DB::transaction(function () use ($user, $purpose, $input): OtpResult {
            $code = $this->live($user, $purpose)->latest('id')->lockForUpdate()->first();

            if ($code === null) {
                // Nothing live: either never sent, already used, or expired.
                return $this->hasExpiredCode($user, $purpose) ? OtpResult::Expired : OtpResult::Invalid;
            }

            if ($code->attempts >= (int) config('auction.otp.max_attempts')) {
                return OtpResult::TooManyAttempts;
            }

            if (! Hash::check(trim($input), $code->code_hash)) {
                $code->increment('attempts');

                return $code->attempts >= (int) config('auction.otp.max_attempts')
                    ? OtpResult::TooManyAttempts
                    : OtpResult::Invalid;
            }

            $code->update(['consumed_at' => now()]);

            return OtpResult::Verified;
        });
    }

    /** Whether the user has a code for this purpose that can still be entered. */
    public function hasLiveCode(User $user, OtpPurpose $purpose): bool
    {
        return $this->live($user, $purpose)->exists();
    }

    /** Seconds until another code may be sent; zero when one can be sent now. */
    public function secondsUntilNextSend(User $user, OtpPurpose $purpose): int
    {
        $recent = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('created_at', '>', now()->subHour())
            ->orderByDesc('id')
            ->pluck('created_at');

        if ($recent->isEmpty()) {
            return 0;
        }

        $wait = 0;

        $cooldownEnds = $recent->first()->addSeconds((int) config('auction.otp.resend_seconds'));
        if ($cooldownEnds->isFuture()) {
            $wait = (int) ceil(now()->diffInSeconds($cooldownEnds, absolute: true));
        }

        $maxPerHour = (int) config('auction.otp.max_per_hour');
        if ($recent->count() >= $maxPerHour) {
            // The hourly allowance frees up when the oldest counted code ages out.
            $slotFrees = $recent->get($maxPerHour - 1)->addHour();
            $wait = max($wait, (int) ceil(now()->diffInSeconds($slotFrees, absolute: true)));
        }

        return $wait;
    }

    /** @return Builder<OtpCode> */
    private function live(User $user, OtpPurpose $purpose)
    {
        return OtpCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());
    }

    private function hasExpiredCode(User $user, OtpPurpose $purpose): bool
    {
        return OtpCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '<=', now())
            ->where('created_at', '>', now()->subDay())
            ->exists();
    }

    private function generate(): string
    {
        $length = (int) config('auction.otp.length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
