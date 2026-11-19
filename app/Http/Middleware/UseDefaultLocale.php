<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin and seller panels are in English only: they undo the site's
 * language choice (SetLocale), also on the panels' Livewire requests.
 */
final class UseDefaultLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale((string) config('app.locale'));

        return $next($request);
    }
}
