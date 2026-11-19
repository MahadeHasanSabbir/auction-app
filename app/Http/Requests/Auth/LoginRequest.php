<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Trust\Blacklist;
use App\Services\Trust\LoginHistory;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Check the email and password without signing in, and return the account.
     *
     * Signing in happens afterwards, once the emailed code is confirmed or the
     * browser is recognised as trusted.
     *
     * @throws ValidationException
     */
    public function validateCredentials(): User
    {
        $this->ensureIsNotRateLimited();

        // A deactivated account, or a blacklisted address or IP, fails
        // exactly like a wrong password.
        $valid = Auth::validate($this->only('email', 'password') + ['is_active' => true]);
        $user = Auth::getLastAttempted();
        $blocked = app(Blacklist::class)->blocks($this->string('email')->toString(), $this->ip());

        if (! $valid || ! $user instanceof User || $blocked) {
            RateLimiter::hit($this->throttleKey());

            if ($user instanceof User) {
                app(LoginHistory::class)->failed($this, $user, $this->string('email')->toString());
            }

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
