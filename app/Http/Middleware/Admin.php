<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class Admin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect(route('login'));
        }

        $userRole = Auth::user()->role;

        if ($userRole != 0) {
            return $next($request);
        } else {
            return redirect(route('dashboard'));
        }

    }
}
