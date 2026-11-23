<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may use the API with a token: active buyers and seller-company
 * accounts. Platform staff work in the admin panel, not through tokens. A
 * token marked read-only cannot make any change.
 */
class EnsureApiAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_active || $user->isPlatform()) {
            abort(403, __('This account cannot use the API.'));
        }

        // Any token may revoke itself.
        if (! $request->isMethodSafe() && ! $request->routeIs('api.token.revoke') && ! $user->tokenCan('write')) {
            abort(403, __('This token is read-only.'));
        }

        return $next($request);
    }
}
