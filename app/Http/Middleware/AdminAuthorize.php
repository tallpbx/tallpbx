<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that authorizes panel users against a specific Gate permission.
 *
 * The middleware name is historical. In the unified panel, both admin and
 * tenant users can access module routes when their model grants the ability.
 * Tenant users still cannot receive admin.* abilities because User filters
 * those permissions before Gate checks.
 *
 * Usage: ->middleware('admin.can:extensions.view')
 */
class AdminAuthorize
{
    /**
     * Handle an incoming request.
     *
     * @param  string  $ability  The Gate ability (permission name) to check
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $actor = $this->panelActor();

        if ($actor === null) {
            throw new AuthenticationException(
                'Unauthenticated.',
                guards: ['admin', 'web'],
            );
        }

        if (! Gate::forUser($actor)->check($ability)) {
            throw new AuthorizationException(
                message: "You don't have permission: {$ability}",
            );
        }

        return $next($request);
    }

    /**
     * Resolve the currently active unified-panel actor.
     */
    private function panelActor(): ?Authenticatable
    {
        if (session()->has('impersonation.original_admin_id') && Auth::guard('web')->check()) {
            return Auth::guard('web')->user();
        }

        return Auth::guard('admin')->user() ?? Auth::guard('web')->user();
    }
}
