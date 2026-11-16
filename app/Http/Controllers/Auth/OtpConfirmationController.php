<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOtpConfirmed;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpThrottledException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Asks a signed-in user for a fresh emailed code before a sensitive page
 * opens (see EnsureOtpConfirmed).
 */
class OtpConfirmationController extends Controller
{
    public function show(Request $request, OtpService $otp): View|RedirectResponse
    {
        if (EnsureOtpConfirmed::isConfirmed($request)) {
            return redirect()->intended(route('profile.security'));
        }

        $user = $request->user();
        $error = null;

        // Arriving here sends the code, unless one is already waiting.
        if (! $otp->hasLiveCode($user, OtpPurpose::SensitiveAction)) {
            try {
                $otp->send($user, OtpPurpose::SensitiveAction, $request->ip());
            } catch (OtpThrottledException $e) {
                $error = $e->userMessage();
            }
        }

        return view('auth.confirm-code', [
            'email' => $user->email,
            'resendIn' => $otp->secondsUntilNextSend($user, OtpPurpose::SensitiveAction),
            'sendError' => $error,
        ]);
    }

    public function store(Request $request, OtpService $otp): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:12']]);

        $result = $otp->verify($request->user(), OtpPurpose::SensitiveAction, $request->string('code')->value());

        if (! $result->passed()) {
            return back()->withErrors(['code' => $result->message()]);
        }

        EnsureOtpConfirmed::markConfirmed($request);

        return redirect()->intended(route('profile.security'));
    }

    public function resend(Request $request, OtpService $otp): RedirectResponse
    {
        try {
            $otp->send($request->user(), OtpPurpose::SensitiveAction, $request->ip());
        } catch (OtpThrottledException $e) {
            return back()->withErrors(['code' => $e->userMessage()]);
        }

        return back()->with('status', __('A new code is on its way.'));
    }
}
