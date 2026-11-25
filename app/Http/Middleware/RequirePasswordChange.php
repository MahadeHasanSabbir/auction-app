<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account given a temporary password by the system owner works in the
 * admin panel only after choosing its own: until then every panel page sends
 * it to the security page.
 */
final class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->password_change_required) {
            return redirect()->route('profile.security')
                ->with('status', __('Choose your own password before you continue.'));
        }

        return $next($request);
    }
}
