<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that verifies the user is authenticated as an admin.
 *
 * Redirects unauthenticated users to the admin login page.
 * Should be applied to all admin-prefixed routes.
 */
class AdminMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check()) {
            return redirect()->route('panel.login');
        }

        return $next($request);
    }
}
