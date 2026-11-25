<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Team\AcceptInvitation;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The page an emailed team invitation links to. The invited person creates
 * their member account here and then signs in the usual way; nothing here
 * signs anyone in, so the emailed code step is never skipped.
 */
class InvitationController extends Controller
{
    public function show(string $token, AcceptInvitation $accept): View
    {
        return view('auth.invitation', [
            'token' => $token,
            'invitation' => $accept->find($token),
        ]);
    }

    public function store(Request $request, string $token, AcceptInvitation $accept): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $accept->handle($token, $data);

        return redirect()->route('login')->with('status', __('Your account is ready. Sign in to open the seller panel.'));
    }
}
