<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Otp\OtpResult;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpThrottledException;
use App\Services\TrustedDevices;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Forgotten password, by emailed code: the address is asked for, a code is
 * sent to it, and the code plus a new password finish the reset.
 *
 * The answer never says whether an address has an account: an unknown or
 * closed address reaches the same code page, where every code is wrong.
 */
class PasswordResetController extends Controller
{
    public const SESSION_KEY = 'password.reset';

    /** A half-finished reset is abandoned after this long. */
    private const MINUTES = 15;

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request, OtpService $otp): RedirectResponse
    {
        $email = Str::lower(trim((string) $request->validate(['email' => ['required', 'email', 'max:255']])['email']));

        $request->session()->put(self::SESSION_KEY, ['email' => $email, 'started_at' => now()->getTimestamp()]);

        $user = $this->userFor($email);

        if ($user !== null) {
            try {
                $otp->send($user, OtpPurpose::PasswordReset, $request->ip());
            } catch (OtpThrottledException) {
                // A code went out moments ago; the page offers a resend later.
            }
        }

        return redirect()->route('password.reset');
    }

    public function edit(Request $request, OtpService $otp): View|RedirectResponse
    {
        $email = $this->pendingEmail($request);

        if ($email === null) {
            return redirect()->route('password.request');
        }

        $user = $this->userFor($email);

        return view('auth.reset-password', [
            'email' => $email,
            'resendIn' => $user === null ? 0 : $otp->secondsUntilNextSend($user, OtpPurpose::PasswordReset),
        ]);
    }

    public function update(Request $request, OtpService $otp, TrustedDevices $devices): RedirectResponse
    {
        $email = $this->pendingEmail($request);

        if ($email === null) {
            return redirect()->route('password.request');
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:12'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $this->userFor($email);
        $result = $user === null ? OtpResult::Invalid : $otp->verify($user, OtpPurpose::PasswordReset, $validated['code']);

        if ($user === null || ! $result->passed()) {
            return back()->withErrors(['code' => $result->message()]);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
            'password_change_required' => false,
        ]);

        // The code reached the inbox, so the address is proven too.
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()]);
        }

        $user->save();

        // Whoever knew the old password, or kept a trusted browser, gains nothing.
        $devices->forgetAll($user);
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $request->session()->forget(self::SESSION_KEY);

        event(new PasswordReset($user));

        return redirect()->route('login')->with('status', __('Your password has been changed. Sign in with the new one.'));
    }

    public function resend(Request $request, OtpService $otp): RedirectResponse
    {
        $email = $this->pendingEmail($request);

        if ($email === null) {
            return redirect()->route('password.request');
        }

        $user = $this->userFor($email);

        if ($user !== null) {
            try {
                $otp->send($user, OtpPurpose::PasswordReset, $request->ip());
            } catch (OtpThrottledException $e) {
                return back()->withErrors(['code' => $e->userMessage()]);
            }
        }

        return back()->with('status', __('A new code is on its way.'));
    }

    private function pendingEmail(Request $request): ?string
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || now()->getTimestamp() - (int) ($pending['started_at'] ?? 0) > self::MINUTES * 60) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return is_string($pending['email'] ?? null) ? $pending['email'] : null;
    }

    /** Only an open, active account can reset its password. */
    private function userFor(string $email): ?User
    {
        return User::query()->where('email', $email)->where('is_active', true)->first();
    }
}
