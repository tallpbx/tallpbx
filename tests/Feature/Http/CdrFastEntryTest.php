<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CdrIngestionController;
use App\Providers\AppServiceProvider;
use App\Support\CdrFastBootstrap;
use App\Support\MinimalBootstrap;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Redis\RedisServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Testing\TestResponse;
use Illuminate\View\ViewServiceProvider;

/*
 * Write-equivalence gate for the fast CDR entry point.
 *
 * The fast entry boots the container with a short provider allowlist instead
 * of the ~90 providers a normal request loads. That is only safe if the
 * stream receives exactly the same bytes, so these tests post one payload
 * through the normal API route and through CdrFastBootstrap::handle(), then
 * compare the responses and the buffered stream entries.
 *
 * The bootstrap is exercised directly rather than through public/cdr-fast.php
 * because that file is only reachable through nginx in production; the file
 * itself stays a thin shim around this class.
 */

/**
 * In-memory stand-in for the CDR stream connection.
 *
 * The fast entry resolves its own Redis manager, so the test binds this
 * object as the caller's `redis` binding; the minimal container reuses it
 * the same way it already reuses the caller's configuration and database.
 */
class FakeCdrRedisConnection
{
    /** @var array<string, array<string, string>> */
    public array $entries = [];

    /** When set, the next append throws it (the buffer-outage path). */
    public ?RuntimeException $failure = null;

    /**
     * Mirror XADD and return a stream id for the appended entry.
     *
     * @param  array<string, string>  $fields
     */
    public function xadd(string $key, string $id, array $fields): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $entryId = (count($this->entries) + 1).'-0';
        $this->entries[$entryId] = $fields;

        return $entryId;
    }
}

/**
 * Redis manager stand-in exposing the fake stream connection.
 */
class FakeCdrRedisManager
{
    /**
     * Create the manager around one fake connection.
     */
    public function __construct(public readonly FakeCdrRedisConnection $store) {}

    /**
     * Return the fake connection for any connection name.
     */
    public function connection(?string $name = null): FakeCdrRedisConnection
    {
        return $this->store;
    }
}

/**
 * Post one raw payload through the normal API route.
 *
 * @param  array<string, string>  $server  Header/server variables for the call
 */
function postCdrThroughNormalRoute(array $server, string $body): TestResponse
{
    return test()->call('POST', '/api/v1/cdr', [], [], [], $server, $body);
}

beforeEach(function (): void {
    config(['freeswitch.cdr.token' => str_repeat('f', 64)]);

    $this->stream = new FakeCdrRedisConnection;
    app()->instance('redis', new FakeCdrRedisManager($this->stream));

    // A facade root resolved earlier in the test process would bypass the
    // container binding above and reach the real Redis server.
    Facade::clearResolvedInstance('redis');
});

it('buffers byte-identical stream entries through the fast entry', function (): void {
    $payload = '{"variables":{"uuid":"fast-entry-equivalence","domain_name":"example.com"}}';
    $server = [
        'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('tallpbx:'.str_repeat('f', 64)),
        'CONTENT_TYPE' => 'application/json',
    ];

    $normal = postCdrThroughNormalRoute($server, $payload);
    $normal->assertOk();

    $fast = CdrFastBootstrap::handle(Request::create('/api/v1/cdr', 'POST', [], [], [], $server, $payload));

    expect($fast->getStatusCode())->toBe($normal->getStatusCode())
        ->and($fast->getContent())->toBe($normal->getContent())
        ->and($fast->headers->get('Content-Type'))->toBe($normal->headers->get('Content-Type'));

    // Both paths must have appended the untouched document, twice in total.
    expect($this->stream->entries)->toHaveCount(2);

    $payloads = array_map(static fn (array $fields): string => $fields['data'], array_values($this->stream->entries));

    expect($payloads)->toBe([$payload, $payload]);
});

it('rejects a request without a token through the fast entry', function (): void {
    $fast = CdrFastBootstrap::handle(Request::create('/api/v1/cdr', 'POST', [], [], [], [], '{"variables":{}}'));

    expect($fast->getStatusCode())->toBe(401)
        ->and($this->stream->entries)->toBe([]);
});

it('rejects an empty payload through the fast entry', function (): void {
    $server = ['HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('f', 64)];

    $fast = CdrFastBootstrap::handle(Request::create('/api/v1/cdr', 'POST', [], [], [], $server, ''));

    expect($fast->getStatusCode())->toBe(422)
        ->and($this->stream->entries)->toBe([]);
});

it('answers 503 through the fast entry when the stream is unavailable', function (): void {
    // The same failover contract as the full path: FreeSWITCH retries, then
    // spools the record to disk, so the entry must never answer 200 on a
    // failed append or throw a 500 the module would not retry.
    $this->stream->failure = new RuntimeException('connection refused');

    $server = ['HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('f', 64)];

    $fast = CdrFastBootstrap::handle(Request::create('/api/v1/cdr', 'POST', [], [], [], $server, '{"variables":{}}'));

    expect($fast->getStatusCode())->toBe(503)
        ->and($this->stream->entries)->toBe([]);
});

it('pins the provider allowlist to the shared core', function (): void {
    // The speed depends on this list staying short, and the byte-equivalence
    // test above depends on nothing the request needs being removed. Pinning
    // the exact list makes a re-added provider a failing test rather than a
    // quiet regression, the same gate the XML entry uses.
    $method = new ReflectionMethod(CdrFastBootstrap::class, 'providerClasses');

    /** @var list<class-string> $providers */
    $providers = $method->invoke(null);

    expect($providers)->toBe([
        FoundationServiceProvider::class,
        CacheServiceProvider::class,
        RedisServiceProvider::class,
        FilesystemServiceProvider::class,
        AppServiceProvider::class,
    ]);

    // There is no database work, no encrypted cast and no view on this path,
    // and no module code is touched, so none of these may creep back in.
    expect($providers)
        ->not->toContain(DatabaseServiceProvider::class)
        ->not->toContain(EncryptionServiceProvider::class)
        ->not->toContain(ViewServiceProvider::class);

    foreach ($providers as $provider) {
        expect($provider)->not->toStartWith('Modules\\');
    }
});

it('restores the caller container when booting throws, so the shim can fall back', function (): void {
    // Fail-open is the record-safety contract: a broken fast entry must leave
    // the caller exactly as it found it, because the shim then re-serves the
    // request through index.php on the same PHP process.
    $previous = app();
    $previousFacade = Facade::getFacadeApplication();
    $previousRole = getenv('TALLPBX_WORKER_ROLE');

    $broken = new class extends MinimalBootstrap
    {
        /**
         * Fail while the container is being built.
         *
         * @return list<class-string>
         */
        protected static function providerClasses(): array
        {
            throw new RuntimeException('boot failed');
        }

        /**
         * Describe a route the boot never reaches.
         *
         * @return array{methods: list<string>, controller: class-string, action: string, middleware: list<string>, name: string}
         */
        protected static function route(): array
        {
            return [
                'methods' => ['post'],
                'controller' => CdrIngestionController::class,
                'action' => 'store',
                'middleware' => [],
                'name' => 'test.broken',
            ];
        }
    };

    expect(fn () => $broken::handle(Request::create('/api/v1/cdr', 'POST')))
        ->toThrow(RuntimeException::class, 'boot failed');

    expect(Container::getInstance())->toBe($previous)
        ->and(Facade::getFacadeApplication())->toBe($previousFacade)
        ->and(getenv('TALLPBX_WORKER_ROLE'))->toBe($previousRole);
});

it('ships a front controller that falls back to the full application', function (): void {
    // The shim is only reachable through nginx in production, so the fallback
    // is asserted from its source: every failure must re-serve the request
    // through index.php rather than answering an error FreeSWITCH would not
    // retry.
    $shim = (string) file_get_contents(base_path('public/cdr-fast.php'));

    expect($shim)->toContain('CdrFastBootstrap::handle')
        ->and($shim)->toContain("require __DIR__.'/index.php'")
        ->and($shim)->toContain('error_log');
});

it('serves the fallback through index.php when the fast entry already booted', function (): void {
    // The shim's fail-open contract: after CdrFastBootstrap has required
    // bootstrap/app.php, a Throwable falls back to index.php in the same
    // process. index.php must therefore build the application again instead
    // of using require_once, which returns true once the file was included
    // and makes the fallback fatal.
    $script = implode("\n", [
        "define('LARAVEL_START', microtime(true));",
        'require '.var_export(base_path('vendor/autoload.php'), true).';',
        'require '.var_export(base_path('bootstrap/app.php'), true).';',
        "\$_SERVER['REQUEST_METHOD'] = 'GET';",
        "\$_SERVER['REQUEST_URI'] = '/api/v1/cdr';",
        "\$_SERVER['SCRIPT_NAME'] = '/index.php';",
        "\$_SERVER['HTTP_HOST'] = '127.0.0.1';",
        "\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';",
        "\$_SERVER['SERVER_PORT'] = '80';",
        'require '.var_export(base_path('public/index.php'), true).';',
    ]);

    $command = sprintf(
        'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync %s -d display_errors=1 -d error_reporting=E_ALL -r %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($script),
    );

    $output = (string) shell_exec($command);

    // The full application must answer this request itself — whatever status
    // its own policy picks for a bare CLI request — which only happens when
    // index.php built a real application after the first include of
    // bootstrap/app.php. Before the fix the fallback produced a fatal instead.
    expect($output)->not->toContain('Fatal error')
        ->and($output)->not->toContain('handleRequest() on true')
        ->and($output)->toContain('<html');
});
