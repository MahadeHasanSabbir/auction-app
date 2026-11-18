<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Accounts\SignInWithGoogle;
use App\Http\Controllers\Auth\Concerns\SignsUsersIn;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * "Continue with Google" for buyers. Google has checked the address and
 * the sign-in, so no emailed code follows; it ends in the same signIn()
 * step as the code flow. It does not count as a fresh code for the
 * step-up pages (security settings, withdrawals).
 */
class GoogleController extends Controller
{
    use SignsUsersIn;

    public static function enabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function redirect(): SymfonyRedirect
    {
        abort_unless(self::enabled(), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, SignInWithGoogle $signIn): RedirectResponse
    {
        abort_unless(self::enabled(), 404);

        if ($request->filled('error')) {
            return redirect()->route('login');
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->withErrors(['email' => __('Google sign-in did not complete. Please try again.')]);
        }

        try {
            $user = $signIn->handle($google);
        } catch (ValidationException $e) {
            return redirect()->route('login')->withErrors($e->errors());
        }

        return $this->signIn($request, $user, false);
    }
}
