<?php

namespace App\Services\Trust;

use App\Enums\LoginMethod;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Writes the sign-in history: each sign-in, and each wrong password for an existing account. */
final class LoginHistory
{
    public function succeeded(Request $request, User $user, LoginMethod $method): void
    {
        $this->write($request, $user, (string) $user->email, $method, true);
    }

    /** A wrong password, or a suspended or blocked account, for an address that has an account. */
    public function failed(Request $request, ?User $user, string $email): void
    {
        $this->write($request, $user, $email, LoginMethod::Password, false);
    }

    private function write(Request $request, ?User $user, string $email, LoginMethod $method, bool $succeeded): void
    {
        $event = new LoginEvent;
        $event->forceFill([
            'user_id' => $user?->id,
            'email' => Str::limit(mb_strtolower($email), 250, ''),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'method' => $method,
            'succeeded' => $succeeded,
            'created_at' => now(),
        ])->save();
    }
}
