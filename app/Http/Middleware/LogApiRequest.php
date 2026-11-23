<?php

namespace App\Http\Middleware;

use App\Models\ApiRequest;
use App\Models\ApiToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes one row per authenticated API request, after the response has gone
 * out, so logging never slows or breaks an answer. Requests without a valid
 * token are not recorded.
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('api.started_at', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return;
            }

            $token = $user->currentAccessToken();
            $started = (float) $request->attributes->get('api.started_at', microtime(true));

            ApiRequest::query()->forceCreate([
                'user_id' => $user->id,
                'token_id' => $token instanceof ApiToken && $token->exists ? $token->id : null,
                'token_name' => $token instanceof ApiToken && $token->exists ? mb_substr($token->name, 0, 120) : null,
                'method' => mb_substr($request->method(), 0, 8),
                'route' => mb_substr($request->route()?->uri() ?? $request->path(), 0, 255),
                'status' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent() === null ? null : mb_substr($request->userAgent(), 0, 255),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
