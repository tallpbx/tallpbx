<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ImpersonationServiceInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that authenticates against either the admin or web guard.
 *
 * In the unified panel, both Admin and User models access the same routes.
 * This middleware checks both guards:
 *   1. Admin guard — authenticates admin users (system-wide access)
 *   2. Web guard — authenticates tenant users (tenant-scoped access)
 *
 * Tenant-user requests are also passed through ScopeTenant so every shared
 * panel route receives an authorized tenant context before application code
 * can query tenant-owned records.
 */
class AuthPanelMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('panel.impersonation.stop') && Auth::guard('admin')->check()) {
            return $next($request);
        }

        if (Auth::guard('web')->check() && app(ImpersonationServiceInterface::class)->isImpersonating()) {
            return app(ScopeTenant::class)->handle($request, $next);
        }

        if (Auth::guard('admin')->check()) {
            return $next($request);
        }

        if (Auth::guard('web')->check()) {
            return app(ScopeTenant::class)->handle($request, $next);
        }

        // No authenticated session on either guard — redirect to admin login.
        // The admin login page provides links to the tenant login if needed.
        return redirect()->route('panel.login');
    }
}
