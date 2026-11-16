<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Accounts\RegisterBuyer;
use App\Actions\Accounts\RegisterCompany;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request): View
    {
        return view('auth.register', [
            'accountType' => $request->query('type') === 'seller' ? 'seller' : 'buyer',
        ]);
    }

    /**
     * Create a buyer account, or a seller company with its owner account.
     *
     * Either way the new user is signed in and sent to confirm the email
     * address with the code the Registered event has just emailed.
     */
    public function store(Request $request, RegisterBuyer $registerBuyer, RegisterCompany $registerCompany): RedirectResponse
    {
        $data = $request->validate([
            'account_type' => ['required', Rule::in(['buyer', 'seller'])],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'confirmed', Password::defaults()],
            'company_name' => ['exclude_unless:account_type,seller', 'required', 'string', 'min:2', 'max:255'],
            'phone' => ['exclude_unless:account_type,seller', 'nullable', 'string', 'max:32'],
        ]);

        $user = $data['account_type'] === 'seller'
            ? $registerCompany->handle($data)
            : $registerBuyer->handle($data);

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
