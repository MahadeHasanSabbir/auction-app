<?php

namespace App\Actions\Accounts;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as ProviderUser;

/**
 * Finds or creates the buyer behind a Google account. A known Google
 * account signs in its user; otherwise a buyer with the same (Google
 * verified) address is linked, or a new buyer is created with a verified
 * email and a random password (they can set one through "forgot password").
 * Seller and staff accounts never sign in this way.
 */
final class SignInWithGoogle
{
    public function __construct(private readonly RegisterBuyer $register) {}

    /** @throws ValidationException under `email` when this Google account cannot be used here */
    public function handle(ProviderUser $google): User
    {
        $email = mb_strtolower(trim((string) $google->getEmail()));
        $raw = method_exists($google, 'getRaw') ? (array) $google->getRaw() : [];
        $verified = filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOL);

        if ($email === '' || ! $verified) {
            $this->refuse(__('Your Google account has no verified email address.'));
        }

        return DB::transaction(function () use ($google, $email): User {
            $link = SocialAccount::query()
                ->where('provider', 'google')
                ->where('provider_id', (string) $google->getId())
                ->lockForUpdate()
                ->first();

            $user = $link !== null
                ? User::query()->find($link->user_id)
                : User::withTrashed()->where('email', $email)->first();

            if ($user === null && $link === null) {
                $user = $this->register->handle([
                    'name' => mb_substr(trim((string) ($google->getName() ?: Str::before($email, '@'))), 0, 255),
                    'email' => $email,
                    'password' => Str::password(40),
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            if ($user === null || $user->trashed()) {
                $this->refuse(__('This account is closed.'));
            }

            if (! $user->isBuyer()) {
                $this->refuse(__('Google sign-in is for buyers. Sign in with your email and password.'));
            }

            if (! $user->is_active) {
                // Same answer as a wrong password: a suspended account learns nothing.
                $this->refuse(__('auth.failed'));
            }

            $link ??= new SocialAccount;
            $link->forceFill([
                'user_id' => $user->id,
                'provider' => 'google',
                'provider_id' => (string) $google->getId(),
                'email' => $email,
                'last_used_at' => now(),
            ])->save();

            // Google has confirmed the address.
            if ($user->email_verified_at === null && $user->email === $email) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        });
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['email' => $message]);
    }
}
