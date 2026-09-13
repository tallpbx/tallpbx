<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects authenticated admin users away from login pages.
 *
 * If the user is already authenticated as an admin, sending them
 * to the login page makes no sense — redirect to the dashboard instead.
 */
class RedirectIfAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('panel.dashboard');
        }

        return $next($request);
    }
}
