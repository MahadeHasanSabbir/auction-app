<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOtpConfirmed;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpThrottledException;
use App\Support\Home;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Confirms an email address with a code instead of a link. The code is sent
 * when the account is created and whenever the address changes.
 */
class EmailVerificationController extends Controller
{
    public function notice(Request $request, OtpService $otp): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(Home::pathFor($user));
        }

        return view('auth.verify-email', [
            'email' => $user->email,
            'resendIn' => $otp->secondsUntilNextSend($user, OtpPurpose::EmailVerification),
        ]);
    }

    public function verify(Request $request, OtpService $otp): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(Home::pathFor($user));
        }

        $request->validate(['code' => ['required', 'string', 'max:12']]);

        $result = $otp->verify($user, OtpPurpose::EmailVerification, $request->string('code')->value());

        if (! $result->passed()) {
            return back()->withErrors(['code' => $result->message()]);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        EnsureOtpConfirmed::markConfirmed($request);

        return redirect()->intended(Home::pathFor($user))
            ->with('status', __('Your email address is confirmed.'));
    }

    public function resend(Request $request, OtpService $otp): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(Home::pathFor($user));
        }

        try {
            $otp->send($user, OtpPurpose::EmailVerification, $request->ip());
        } catch (OtpThrottledException $e) {
            return back()->withErrors(['code' => $e->userMessage()]);
        }

        return back()->with('status', __('A new code is on its way.'));
    }
}
