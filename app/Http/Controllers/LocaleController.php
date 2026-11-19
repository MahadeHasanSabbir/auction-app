<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The language switcher: remembered for the session and on the account. */
final class LocaleController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys((array) config('auction.locales')))],
        ])['locale'];

        $request->session()->put(SetLocale::SESSION_KEY, $locale);

        $user = $request->user();

        if ($user !== null && $user->locale !== $locale) {
            $user->forceFill(['locale' => $locale])->save();
        }

        return redirect()->back(fallback: route('home'));
    }
}
