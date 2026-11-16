<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Auth\Concerns\SignsUsersIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpThrottledException;
use App\Services\TrustedDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    use SignsUsersIn;

    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * First step of signing in: the email and password.
     *
     * A browser the user chose to trust is signed in straight away. Any other
     * browser gets a code by email and continues on the code page.
     */
    public function store(LoginRequest $request, OtpService $otp, TrustedDevices $devices): RedirectResponse
    {
        $user = $request->validateCredentials();
        $remember = $request->boolean('remember');

        if ($devices->isTrusted($user, $request)) {
            return $this->signIn($request, $user, $remember);
        }

        try {
            $otp->send($user, OtpPurpose::Login, $request->ip());
        } catch (OtpThrottledException $e) {
            // Fine if a code is already waiting in the inbox; otherwise the
            // hourly allowance is used up and there is nothing to enter.
            if (! $otp->hasLiveCode($user, OtpPurpose::Login)) {
                throw ValidationException::withMessages(['email' => $e->userMessage()]);
            }
        }

        $request->session()->put(LoginCodeController::SESSION_KEY, [
            'user_id' => $user->id,
            'remember' => $remember,
            'started_at' => now()->getTimestamp(),
        ]);

        return redirect()->route('login.code');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
