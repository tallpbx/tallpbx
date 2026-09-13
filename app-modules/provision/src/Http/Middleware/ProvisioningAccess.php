<?php

declare(strict_types=1);

namespace Modules\Provision\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the public provisioning endpoint using the FusionPBX security model.
 *
 * The endpoint is invisible unless provisioning.enabled is true; when
 * configured it requires HTTP Basic auth and/or matches the request IP
 * against a CIDR allowlist. Every check runs before the device lookup so
 * callers cannot probe which MAC addresses exist.
 */
class ProvisioningAccess
{
    /**
     * Apply the provisioning access policy.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Disabled by default (FusionPBX parity): the endpoint does not exist.
        if (! config('provisioning.enabled')) {
            abort(404);
        }

        // Optional CIDR allowlist.
        $cidr = config('provisioning.cidr');

        if ($cidr !== null) {
            $ranges = array_values(array_filter(array_map('trim', explode(',', $cidr))));

            if (! IpUtils::checkIp((string) $request->ip(), $ranges)) {
                abort(403, 'Provisioning is restricted by IP address.');
            }
        }

        // Optional HTTP Basic auth; each non-empty (filled) credential is a
        // required factor. A blanked env value is treated as unset, so
        // "blanking" a factor disables it rather than failing open or
        // demanding an empty password from the phone.
        $username = config('provisioning.http_auth_username');
        $password = config('provisioning.http_auth_password');

        if (filled($username) || filled($password)) {
            // Each FILLED factor must match; unset factors impose no
            // requirement (blanking a factor disables it).
            $userOk = ! filled($username) || hash_equals($username, (string) $request->getUser());
            $passOk = ! filled($password) || hash_equals($password, (string) $request->getPassword());

            if (! $userOk || ! $passOk) {
                // The route throttle only sees requests that pass this gate,
                // so failed-auth attempts get their own per-IP limiter here.
                // No-header probes are not counted — they may be legitimate
                // phones that simply do not send credentials.
                $key = 'provision-auth:'.$request->ip();

                if (RateLimiter::tooManyAttempts($key, 5)) {
                    return response('Too Many Attempts.', 429);
                }

                if ($request->getUser() !== null || $request->getPassword() !== null) {
                    RateLimiter::hit($key, 60);
                }

                return response('Unauthorized.', 401, ['WWW-Authenticate' => 'Basic realm="provisioning"']);
            }

            // Successful authentication resets the failure counter.
            RateLimiter::clear('provision-auth:'.$request->ip());
        }

        return $next($request);
    }
}
