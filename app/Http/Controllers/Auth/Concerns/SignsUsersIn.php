<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\User;
use App\Support\Home;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait SignsUsersIn
{
    /**
     * Start the session for a user whose password, and code or trusted
     * device, have been checked, and send them to their workspace.
     */
    protected function signIn(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::login($user, $remember);

        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return Home::redirect($user);
    }
}
