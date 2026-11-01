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

        $logEnabled = (bool) config('freeswitch.xml_handler.log_timing', false);

        // Headers are opt-in twice over: the config flag must be enabled AND
        // the caller must request them, so internal cost data never leaks by
        // accident on the public xml-handler endpoint.
        $headersEnabled = (bool) config('freeswitch.xml_handler.timing_headers', false)
            && $request->header('X-TallPBX-Timing') === '1';

        // Query counting feeds both the log line and the DB-Queries header.
        if ($logEnabled || $headersEnabled) {
            DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryTimeMs): void {
                $queryCount++;
                $queryTimeMs += (float) $query->time;
            });
        }

        $response = $next($request);

        $elapsedMs = $this->elapsedMs($startedAt);

        if ($logEnabled) {
            $this->logTiming($request, $response, $elapsedMs, $queryCount, $queryTimeMs);
        }

        if ($headersEnabled) {
            $this->applyTimingHeaders($response, $elapsedMs, $queryCount);
        }

        return $response;
    }

    /**
     * Write a timing log entry without including secrets or full request data.
     */
    private function logTiming(
        Request $request,
        Response $response,
        float $elapsedMs,
        int $queryCount,
        float $queryTimeMs
    ): void {
        $dbTimeMs = round($queryTimeMs, 2);

        Log::info('XML Handler timing.', [
            'elapsed_ms' => $elapsedMs,
            'db_query_count' => $queryCount,
            'db_time_ms' => $dbTimeMs,
            // Clamp at zero: the DB timer can exceed wall time by a fraction on clock skew.
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

    /**
     * Measure wall time since a start timestamp in milliseconds.
     */
    private function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }

    /**
     * Attach opt-in app-side timing headers to an XML handler response.
     */
    private function applyTimingHeaders(Response $response, float $elapsedMs, int $queryCount): void
    {
        $response->headers->set('X-TallPBX-Handler-Ms', number_format($elapsedMs, 2, '.', ''));
        $response->headers->set('X-TallPBX-DB-Queries', (string) $queryCount);

        // Request time includes framework boot, which the middleware window
        // does not cover. Omit it when the entry point did not define the
        // bootstrap timestamp (for example, in unit tests).
        if (defined('LARAVEL_START')) {
            $requestMs = max(0.0, round((microtime(true) - LARAVEL_START) * 1000, 2));
            $response->headers->set('X-TallPBX-Request-Ms', number_format($requestMs, 2, '.', ''));
        }
    }
}
