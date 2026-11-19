<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Enums\LoginMethod;
use App\Models\User;
use App\Services\Trust\LoginHistory;
use App\Support\Home;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait SignsUsersIn
{
    /**
     * Start the session for a user whose password, and code or trusted
     * device, have been checked, and send them to their workspace. The
     * sign-in goes into the account's history.
     */
    protected function signIn(Request $request, User $user, bool $remember, LoginMethod $method): RedirectResponse
    {
        Auth::login($user, $remember);

        app(LoginHistory::class)->succeeded($request, $user, $method);

        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return Home::redirect($user);
    }
}
