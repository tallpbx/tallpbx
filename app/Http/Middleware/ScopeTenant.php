<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that detects the current tenant from the session or
 * authenticated user's default tenant and sets the scoped tenant
 * context on TenantManager.
 *
 * Verifies the user has access to the detected tenant before
 * applying the scope. Uses TenantContext for three-tier resolution:
 * session → authenticated user's default → single-tenant fallback.
 */
class ScopeTenant
{
    /**
     * Handle an incoming request and apply the tenant scope.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        $tenant = $context->current();

        if ($tenant !== null) {
            if (! $this->verifyTenantAccess($request, (string) $tenant->id)) {
                abort(403, 'You do not have access to the requested tenant.');
            }
        } elseif (Auth::guard('web')->check()) {
            abort(403, 'No enabled tenant is available for this account.');
        }

        return $next($request);
    }

    /**
     * Verify that the authenticated user has access to the given tenant.
     */
    protected function verifyTenantAccess(Request $request, string $tenantId): bool
    {
        if (Auth::guard('admin')->check()) {
            return true;
        }

        if (Auth::guard('web')->check()) {
            return Auth::guard('web')->user()->isInTenant((int) $tenantId);
        }

        return false;
    }
}
