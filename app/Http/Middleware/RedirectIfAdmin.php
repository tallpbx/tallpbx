<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects authenticated panel users away from login pages.
 *
 * If the visitor is already authenticated as either a system administrator
 * or a tenant user, sending them to the login page makes no sense — redirect
 * to the unified dashboard instead.
 */
class RedirectIfAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('admin')->check() || Auth::guard('web')->check()) {
            return redirect()->route('panel.dashboard');
        }

        return $next($request);
    }
}
