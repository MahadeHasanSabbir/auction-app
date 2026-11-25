<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Browsers a user chose to trust, which may skip the emailed login code.
 *
 * The browser keeps a random token in an encrypted, HTTP-only cookie; the
 * database keeps only its hash, so a leaked table cannot be replayed.
 */
final class TrustedDevices
{
    public const COOKIE = 'trusted_device';

    public function isTrusted(User $user, Request $request): bool
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return false;
        }

        $device = TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if ($device === null) {
            return false;
        }

        $device->update(['last_used_at' => now(), 'ip_address' => $request->ip()]);

        return true;
    }

    /** Remember this browser and queue the cookie that identifies it. */
    public function trust(User $user, Request $request): void
    {
        $token = Str::random(64);
        $days = (int) config('auction.otp.trusted_device_days');

        TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($days),
        ]);

        Cookie::queue(Cookie::make(self::COOKIE, $token, $days * 24 * 60, httpOnly: true, sameSite: 'lax'));
    }

    /** Called when the password changes or the user asks for it. */
    public function forgetAll(User $user): void
    {
        TrustedDevice::query()->where('user_id', $user->id)->delete();
    }
}
