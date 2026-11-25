<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the sensitive account pages (email, password, deleting the account):
 * the user must have confirmed an emailed code within the last few minutes.
 *
 * Signing in with a code or verifying the email address counts, so a fresh
 * sign-in is not asked twice. A sign-in from a trusted device does not.
 */
class EnsureOtpConfirmed
{
    private const SESSION_KEY = 'auth.otp_confirmed_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (self::isConfirmed($request)) {
            return $next($request);
        }

        // After confirming, return to the page itself, or for a form
        // submission to the page the form was on.
        $request->session()->put(
            'url.intended',
            $request->isMethod('GET') ? $request->fullUrl() : url()->previous(),
        );

        return redirect()->route('otp.confirm');
    }

    public static function isConfirmed(Request $request): bool
    {
        return self::confirmedIn($request->session());
    }

    /** The same check from code that has the session but not the request (Livewire actions). */
    public static function confirmedIn(Session $session): bool
    {
        $confirmedAt = (int) $session->get(self::SESSION_KEY, 0);

        return now()->getTimestamp() - $confirmedAt < (int) config('auction.otp.confirm_minutes') * 60;
    }

    public static function markConfirmed(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
    }
}
