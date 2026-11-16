<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Auth\Concerns\SignsUsersIn;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOtpConfirmed;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpThrottledException;
use App\Services\TrustedDevices;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Second step of signing in: the code that was emailed after the password
 * was accepted. Nobody is signed in until this step succeeds.
 */
class LoginCodeController extends Controller
{
    use SignsUsersIn;

    public const SESSION_KEY = 'login.challenge';

    /** A half-finished sign-in is abandoned after this long. */
    private const CHALLENGE_MINUTES = 15;

    public function create(Request $request, OtpService $otp): View|RedirectResponse
    {
        $user = $this->challengedUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        return view('auth.login-code', [
            'email' => $user->email,
            'resendIn' => $otp->secondsUntilNextSend($user, OtpPurpose::Login),
        ]);
    }

    public function store(Request $request, OtpService $otp, TrustedDevices $devices): RedirectResponse
    {
        $user = $this->challengedUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        $request->validate(['code' => ['required', 'string', 'max:12']]);

        $result = $otp->verify($user, OtpPurpose::Login, $request->string('code')->value());

        if (! $result->passed()) {
            return back()->withErrors(['code' => $result->message()]);
        }

        $remember = (bool) $request->session()->pull(self::SESSION_KEY)['remember'];

        // Entering a code sent to the address also proves the address.
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if ($request->boolean('trust_device')) {
            $devices->trust($user, $request);
        }

        EnsureOtpConfirmed::markConfirmed($request);

        return $this->signIn($request, $user, $remember);
    }

    public function resend(Request $request, OtpService $otp): RedirectResponse
    {
        $user = $this->challengedUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        try {
            $otp->send($user, OtpPurpose::Login, $request->ip());
        } catch (OtpThrottledException $e) {
            return back()->withErrors(['code' => $e->userMessage()]);
        }

        return back()->with('status', __('A new code is on its way.'));
    }

    private function challengedUser(Request $request): ?User
    {
        $challenge = $request->session()->get(self::SESSION_KEY);

        if (! is_array($challenge) || now()->getTimestamp() - (int) ($challenge['started_at'] ?? 0) > self::CHALLENGE_MINUTES * 60) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return User::query()->whereKey($challenge['user_id'] ?? null)->where('is_active', true)->first();
    }
}
