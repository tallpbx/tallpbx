<?php

declare(strict_types=1);

use App\Services\Cdr\CdrPayloadParser;
use App\Services\Cdr\CdrRedisIngest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/*
 * The ingestion endpoint is the first half of the CDR pipeline: FreeSWITCH's
 * mod_json_cdr posts one raw JSON document per call leg, the endpoint only
 * verifies the shared token and appends the payload to the isolated Redis
 * stream, and a 503 answer makes FreeSWITCH spool the record to disk instead
 * of losing it.
 */

/**
 * Recording stand-in for the Redis buffer so the endpoint is exercised
 * without a live Redis dependency.
 */
class CdrIngestSpy extends CdrRedisIngest
{
    /** @var array<int, string> */
    public array $ingested = [];

    public ?RuntimeException $failure = null;

    /**
     * Create the spy with a parser that quarantines into a throwaway path.
     */
    public function __construct()
    {
        parent::__construct(new CdrPayloadParser(sys_get_temp_dir().'/cdr-quarantine-'.uniqid()));
    }

    /**
     * Record the payload, or throw the injected failure.
     */
    public function ingest(string $rawJson): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->ingested[] = $rawJson;

        return '0-1';
    }
}

/**
 * Issue one raw POST against the ingestion endpoint.
 *
 * @param  array<string, string>  $server  Header/server variables for the call
 */
function postRawCdr(string $uri, array $server = [], string $body = ''): TestResponse
{
    return test()->call('POST', $uri, [], [], [], $server, $body);
}

beforeEach(function () {
    config(['freeswitch.cdr.token' => str_repeat('t', 64)]);

    $this->spy = new CdrIngestSpy;
    app()->instance(CdrRedisIngest::class, $this->spy);
});

it('buffers a raw json document presented with basic auth', function () {
    $payload = '{"variables":{"uuid":"a-leg-uuid"},"channel_data":{"direction":"inbound"}}';

    postRawCdr('/api/v1/cdr', [
        'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('tallpbx:'.str_repeat('t', 64)),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertStatus(200);

    expect($this->spy->ingested)->toBe([$payload]);
});

it('buffers a bearer token and a url-encoded cdr form fallback', function () {
    // encode=true in json_cdr.conf.xml posts cdr=<url-encoded json>; the
    // endpoint still buffers the decoded document so a configuration slip
    // does not corrupt the stream.
    $payload = '{"variables":{"uuid":"a-leg-uuid"}}';

    postRawCdr('/api/v1/cdr', [
        'HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('t', 64),
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], 'cdr='.rawurlencode($payload))->assertStatus(200);

    expect($this->spy->ingested)->toBe([$payload]);
});

it('accepts the token as a query parameter', function () {
    postRawCdr('/api/v1/cdr?token='.str_repeat('t', 64), [], '{"variables":{}}')
        ->assertStatus(200);

    expect($this->spy->ingested)->toBe(['{"variables":{}}']);
});

it('rejects a request without any token', function () {
    postRawCdr('/api/v1/cdr', [], '{"variables":{}}')->assertStatus(401);

    expect($this->spy->ingested)->toBe([]);
});

it('rejects a mismatched token', function () {
    postRawCdr('/api/v1/cdr', [
        'HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('x', 64),
    ], '{"variables":{}}')->assertStatus(401);

    expect($this->spy->ingested)->toBe([]);
});

it('rejects basic auth with the wrong username', function () {
    postRawCdr('/api/v1/cdr', [
        'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('freeswitch:'.str_repeat('t', 64)),
    ], '{"variables":{}}')->assertStatus(401);

    expect($this->spy->ingested)->toBe([]);
});

it('fails closed when no token is configured', function () {
    // An unconfigured secret must reject everyone rather than accept everyone.
    config(['freeswitch.cdr.token' => null]);

    postRawCdr('/api/v1/cdr', [
        'HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('t', 64),
    ], '{"variables":{}}')->assertStatus(401);

    expect($this->spy->ingested)->toBe([]);
});

it('answers 503 when the redis buffer is unavailable', function () {
    $this->spy->failure = new RuntimeException('connection refused');

    postRawCdr('/api/v1/cdr', [
        'HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('t', 64),
    ], '{"variables":{}}')->assertStatus(503);

    expect($this->spy->ingested)->toBe([]);
});

it('rejects an empty payload', function () {
    postRawCdr('/api/v1/cdr', [
        'HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('t', 64),
    ], '')->assertStatus(422);

    expect($this->spy->ingested)->toBe([]);
});

it('isolates the stream on redis database 3 without a key prefix', function () {
    // The unprefixed key keeps the stream literally "pbx:cdr:stream" for
    // operational tooling, mirroring the lua_proxy connection.
    expect(config('database.redis.cdr'))->toMatchArray([
        'database' => '3',
        'prefix' => '',
    ]);
});

it('throttles ingestion with a dedicated per-ip limiter aligned to peak call load', function () {
    // Two legs per call at the documented 15-20 calls/sec ceiling is
    // 1,800-2,400 posts per minute, so the default matches the xml handler.
    $limit = RateLimiter::limiter('cdr-ingestion')(Request::create('/api/v1/cdr', 'POST'));

    expect($limit->maxAttempts)->toBe(6000)
        ->and($limit->key)->toBe('127.0.0.1');
});

it('registers the ingestion route under the dedicated limiter', function () {
    $route = app('router')->getRoutes()->getByName('api.v1.cdr.store');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:cdr-ingestion');
});
