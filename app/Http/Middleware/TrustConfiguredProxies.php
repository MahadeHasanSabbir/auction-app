<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Trusts the load balancer or proxy named in auction.trusted_proxies
 * (TRUSTED_PROXIES), so request IPs (throttles, bids, sign-ins, the
 * blacklist) are the visitor's and not the proxy's. Unset: nothing is
 * trusted, and forwarded headers are ignored.
 */
class TrustConfiguredProxies extends TrustProxies
{
    /** @return array<int, string>|string|null */
    protected function proxies()
    {
        $configured = config('auction.trusted_proxies');

        if (! is_string($configured) || $configured === '') {
            return null;
        }

        return $configured === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
