<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs lightweight timing information for FreeSWITCH XML handler requests.
 */
class LogXmlHandlerTiming
{
    /**
     * Handle an XML handler request and optionally log how long Laravel spent processing it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $queryCount = 0;
        $queryTimeMs = 0.0;

        if ((bool) config('freeswitch.xml_handler.log_timing', false)) {
            DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryTimeMs): void {
                $queryCount++;
                $queryTimeMs += (float) $query->time;
            });
        }

        $response = $next($request);

        if ((bool) config('freeswitch.xml_handler.log_timing', false)) {
            $this->logTiming($request, $response, $startedAt, $queryCount, $queryTimeMs);
        }

        return $response;
    }

    /**
     * Write a timing log entry without including secrets or full request data.
     */
    private function logTiming(
        Request $request,
        Response $response,
        float $startedAt,
        int $queryCount,
        float $queryTimeMs
    ): void {
        $elapsedMs = round((microtime(true) - $startedAt) * 1000, 2);
        $dbTimeMs = round($queryTimeMs, 2);

        Log::info('XML Handler timing.', [
            'elapsed_ms' => $elapsedMs,
            'db_query_count' => $queryCount,
            'db_time_ms' => $dbTimeMs,
            'non_db_time_ms' => round(max(0.0, $elapsedMs - $dbTimeMs), 2),
            'section' => $request->input('section', 'directory'),
            'status' => $response->getStatusCode(),
            'response_bytes' => strlen((string) $response->getContent()),
            'context' => $request->input('Caller-Context'),
            'destination' => $request->input('Caller-Destination-Number'),
            'tag_name' => $request->input('tag_name'),
            'key_name' => $request->input('key_name'),
            'key_value' => $request->input('key_value'),
            'sip_auth_username' => $request->input('sip_auth_username', $request->input('user')),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);
    }
}
