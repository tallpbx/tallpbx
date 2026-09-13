<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches a unique correlation ID to every incoming request.
 *
 * The ID is stored on the request and added to the log context so every
 * log line written while handling the request can be correlated with the
 * error page shown to the user.
 */
class AttachRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = 'req_'.bin2hex(random_bytes(8));

        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        return $next($request);
    }
}
