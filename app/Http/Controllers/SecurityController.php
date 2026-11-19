<?php

namespace App\Http\Controllers;

use App\Models\LoginEvent;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TrustedDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The sensitive account settings: email address, password, trusted devices,
 * the recent sign-ins and deleting the account. Every route here sits behind EnsureOtpConfirmed.
 */
class SecurityController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('account.security', [
            'user' => $user,
            'devices' => TrustedDevice::query()
                ->where('user_id', $user->id)
                ->where('expires_at', '>', now())
                ->latest('last_used_at')
                ->get(),
            'logins' => LoginEvent::query()->where('user_id', $user->id)->latest('id')->limit(10)->get(),
        ]);
    }

    /**
     * Change the email address. The new address is unconfirmed until the code
     * sent to it is entered, and the account is held at that step until then.
     */
    public function updateEmail(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validateWithBag('updateEmail', [
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ]);

        if ($data['email'] === $user->email) {
            return back()->with('status', __('That is already your email address.'));
        }

        $user->email = $data['email'];
        $user->email_verified_at = null;
        $user->save();

        $user->sendEmailVerificationNotification();

        return redirect()->route('verification.notice');
    }

    public function forgetDevices(Request $request, TrustedDevices $devices): RedirectResponse
    {
        $devices->forgetAll($request->user());

        return back()->with('status', __('All trusted devices were removed. The next sign-in will ask for a code.'));
    }

    /**
     * Close a buyer account. It is soft-deleted: sign-in stops working while
     * bids, orders and ledger history stay intact.
     */
    public function destroy(Request $request, TrustedDevices $devices): RedirectResponse
    {
        $user = $request->user();

        // Staff and company accounts are managed by their administrators.
        abort_unless($user->isBuyer(), 403);

        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $wallet = Wallet::query()->where('user_id', $user->id)->first();

        if ($wallet !== null && (! $wallet->balance->isZero() || ! $wallet->held->isZero())) {
            throw ValidationException::withMessages([
                'password' => __('Your wallet still holds money. Withdraw the balance and wait for open bids to finish before closing the account.'),
            ])->errorBag('userDeletion');
        }

        Auth::logout();

        $devices->forgetAll($user);
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', __('Your account is closed.'));
    }
}
