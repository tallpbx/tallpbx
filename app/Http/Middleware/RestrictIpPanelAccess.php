<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict panel access when the request arrives via an IP address.
 *
 * When operators disable direct-IP panel access, this middleware returns
 * 403 for any panel request whose Host header is an IP address (IPv4 or
 * IPv6) rather than a domain name. Domain-based requests are never blocked
 * by this middleware — it only controls the IP-facing login surface.
 *
 * The setting is read from the database-backed Setting model with a short
 * Redis cache so production traffic does not query the database on every
 * panel request.
 */
class RestrictIpPanelAccess
{
    /**
     * Handle an incoming panel request.
     *
     * Returns 403 when direct-IP access is disabled AND the request host
     * is an IP address. All other requests pass through unchanged.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isIpAddress($request->getHost())) {
            return $next($request);
        }

        if ($this->isIpAccessAllowed()) {
            return $next($request);
        }

        abort(403, 'Direct IP panel access is disabled.');
    }

    /**
     * Determine whether the panel may be accessed via a bare IP address.
     *
     * Checks a short-lived cache key first to avoid a database query on
     * every request. When the cache is cold, reads the system-scoped
     * Setting row and caches the result for 60 seconds.
     */
    private function isIpAccessAllowed(): bool
    {
        return (bool) Cache::remember('panel.allow_ip_access', 60, function (): bool {
            $setting = Setting::system()
                ->where('key', 'panel.allow_ip_access')
                ->first();

            // Default true: IP access is allowed until an admin explicitly
            // disables it.
            if ($setting === null) {
                return true;
            }

            return filter_var($setting->value, FILTER_VALIDATE_BOOL);
        });
    }

    /**
     * Return true when the given host string is an IP address.
     *
     * Handles both IPv4 (e.g. 192.168.1.76) and IPv6 (e.g. ::1).
     */
    private function isIpAddress(string $host): bool
    {
        // filter_var with FILTER_VALIDATE_IP already handles both v4 and v6.
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
