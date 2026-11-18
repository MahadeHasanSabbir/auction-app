<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TrustedDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     *
     * Trusted devices are forgotten, so every browser has to pass the emailed
     * code again; whoever knew the old password gains nothing from it.
     */
    public function update(Request $request, TrustedDevices $devices): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();
        $user->forceFill([
            'password' => $validated['password'],
            'password_change_required' => false,
        ])->save();

        // The panels' AuthenticateSession signs out sessions whose stored
        // password hash is stale; this one changed it, so it stays signed in.
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $user->getAuthPassword());

        $devices->forgetAll($user);

        return back()->with('status', __('Password changed.'));
    }
}
