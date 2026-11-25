<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The site's language: the visitor's choice for this session, else the
 * signed-in user's saved choice, else the default. The panels set it back
 * (UseDefaultLocale).
 */
final class SetLocale
{
    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locales = array_keys((array) config('auction.locales'));
        $chosen = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        $chosen ??= $request->user()?->locale;

        if (is_string($chosen) && in_array($chosen, $locales, true)) {
            app()->setLocale($chosen);
        }

        return $next($request);
    }
}
