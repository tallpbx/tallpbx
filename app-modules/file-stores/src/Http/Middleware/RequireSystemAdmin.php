<?php

declare(strict_types=1);

namespace Modules\FileStores\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts system-owned storage credentials to the admin guard.
 */
class RequireSystemAdmin
{
    /**
     * Deny tenant users before the file store route reaches Livewire.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Auth::guard('admin')->check(), 403);

        return $next($request);
    }
}
