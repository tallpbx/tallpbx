<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Services\ImpersonationService;
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
        // The stop action stays reachable so a supervisor can always end an
        // impersonation, even when the target or the supervisor is disabled.
        if ($request->routeIs('panel.impersonation.stop') && (Auth::guard('admin')->check() || app(ImpersonationServiceInterface::class)->isImpersonating())) {
            return $next($request);
        }

        if (app(ImpersonationServiceInterface::class)->isImpersonating()) {
            $originalAdmin = Admin::query()->find(session(ImpersonationService::SESSION_ORIGINAL_ADMIN_ID));

            // A supervisor disabled mid-session must not keep acting through
            // the impersonated user: end the session instead of continuing as
            // that user.
            if ($originalAdmin === null || ! $originalAdmin->enabled) {
                Auth::guard('web')->logout();
                Auth::guard('admin')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('panel.login')->withErrors(['email' => __('auth.disabled')]);
            }

            // A disabled target ends only the impersonation; the administrator
            // continues in their own session.
            if (Auth::guard('web')->user()?->enabled === false) {
                app(ImpersonationServiceInterface::class)->stop();
            } else {
                return app(ScopeTenant::class)->handle($request, $next);
            }
        }

        // A disabled session fails closed on both guards. This loop runs after
        // the impersonation handling so a disabled administrator is caught
        // before any guard short-circuit.
        foreach (['admin', 'web'] as $guard) {
            $account = Auth::guard($guard)->user();

            if ($account !== null && ! $account->enabled) {
                Auth::guard($guard)->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('panel.login')->withErrors(['email' => __('auth.disabled')]);
            }
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
