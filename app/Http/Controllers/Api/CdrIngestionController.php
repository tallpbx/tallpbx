<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Cdr\CdrRedisIngest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives raw call detail records from FreeSWITCH's mod_json_cdr.
 *
 * The endpoint only verifies the shared token and appends the payload to the
 * Redis stream: no parsing, no MariaDB access, no Eloquent. That keeps the
 * answer fast enough that FreeSWITCH's call teardown never waits on the
 * application. Any failure answers 503 so mod_json_cdr spools the record to
 * its error directory instead of losing it.
 */
class CdrIngestionController extends Controller
{
    /**
     * Create the controller with its stream buffer.
     */
    public function __construct(private readonly CdrRedisIngest $ingest) {}

    /**
     * Authenticate the request and buffer one raw call record.
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->authenticated($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $this->payload($request);

        if ($payload === '') {
            return response()->json(['error' => 'Empty payload'], 422);
        }

        try {
            $this->ingest->ingest($payload);
        } catch (Throwable $exception) {
            Log::error('CDR ingestion buffer unavailable; FreeSWITCH will spool to disk.', [
                'exception' => $exception->getMessage(),
            ]);

            // 503 is the failover contract: mod_json_cdr retries, then writes
            // the record to its error directory for the drain command.
            return response()->json(['error' => 'Ingest unavailable'], 503);
        }

        // An empty 200 is the cheapest success answer; libcurl treats any 2xx
        // as delivered.
        return response()->json([], 200);
    }

    /**
     * Verify the shared token in constant time, failing closed when the
     * secret is not configured.
     */
    private function authenticated(Request $request): bool
    {
        $expected = (string) config('freeswitch.cdr.token');

        if ($expected === '') {
            Log::critical('CDR ingestion: no token is configured. Rejecting all requests.');

            return false;
        }

        $provided = $this->tokenFrom($request);

        if ($provided === null) {
            Log::alert('CDR ingestion failed — no token provided.', ['ip' => $request->ip()]);

            return false;
        }

        if (! hash_equals($expected, $provided)) {
            Log::alert('CDR ingestion failed — token mismatch.', ['ip' => $request->ip()]);

            return false;
        }

        return true;
    }

    /**
     * Extract the token from the three transports mod_json_cdr can use.
     *
     * mod_json_cdr sends Basic credentials as configured in its cred
     * parameter (tallpbx:<token>); the Bearer header and the query parameter
     * mirror the xml-handler fallbacks for manual testing.
     *
     * @return string|null The presented token, or null when absent or malformed
     */
    private function tokenFrom(Request $request): ?string
    {
        $authorization = (string) $request->header('Authorization');

        if ($authorization !== '') {
            // Basic credentials are pinned to the tallpbx username; a
            // different username is a failed handshake, not a fallback case.
            if (str_starts_with(strtolower($authorization), 'basic ')) {
                $decoded = base64_decode(substr($authorization, 6), true);

                if ($decoded === false || ! str_contains($decoded, ':')) {
                    return null;
                }

                [$username, $password] = explode(':', $decoded, 2);

                return $username === 'tallpbx' ? $password : null;
            }

            if (str_starts_with(strtolower($authorization), 'bearer ')) {
                $bearer = trim(substr($authorization, 7));

                return $bearer !== '' ? $bearer : null;
            }
        }

        $query = $request->query('token');

        return is_string($query) && $query !== '' ? $query : null;
    }

    /**
     * Read the raw body, decoding the cdr= form fallback.
     *
     * mod_json_cdr with encode=true posts cdr=<url-encoded json>; decoding it
     * here keeps the stream consistent even when the module configuration
     * drifts from encode=false.
     *
     * @return string The payload to buffer, or an empty string when absent
     */
    private function payload(Request $request): string
    {
        $body = trim((string) $request->getContent());

        if ($body === '') {
            return '';
        }

        if (str_starts_with($body, 'cdr=')) {
            return trim((string) rawurldecode(substr($body, 4)));
        }

        return $body;
    }
}
