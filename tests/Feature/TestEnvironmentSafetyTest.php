<?php

use App\Providers\AppServiceProvider;
use App\Support\TestDatabaseSafety;
use Symfony\Component\Process\Process;

it('always uses an in-memory SQLite database while testing', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});

it('refuses a test database configuration that is not in-memory SQLite', function (): void {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'tallpbx',
    ]);

    expect(fn (): null => TestDatabaseSafety::enforce())
        ->toThrow(LogicException::class, 'Tests must use an in-memory SQLite database.');
});

it('forces every Artisan test mode to use in-memory SQLite', function (bool $parallel): void {
    $externalDatabase = sys_get_temp_dir().'/tallpbx-test-command-'.bin2hex(random_bytes(8)).'.sqlite';
    $arguments = [PHP_BINARY, 'artisan', 'test'];

    if ($parallel) {
        $arguments[] = '--parallel';
    }

    $arguments = [
        ...$arguments,
        '--compact',
        'tests/Feature/TestCommandEnvironmentProbeTest.php',
    ];

    try {
        $process = new Process(
            $arguments,
            base_path(),
            [
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $externalDatabase,
            ],
        );

        $process->run();

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput());
    } finally {
        @unlink($externalDatabase);
    }
})->with([
    'standard test command' => false,
    'parallel test command' => true,
]);

it('detects test runs without relying on the APP_ENV variable', function (): void {
    // Hide APP_ENV in every channel it can live in so only real test-runner
    // detection can answer. A cached configuration overrides APP_ENV, so the
    // safety guard must never depend on that variable.
    $serverValue = $_SERVER['APP_ENV'] ?? null;
    $envValue = $_ENV['APP_ENV'] ?? null;
    $putValue = getenv('APP_ENV');

    unset($_SERVER['APP_ENV'], $_ENV['APP_ENV']);
    putenv('APP_ENV');

    try {
        expect(TestDatabaseSafety::isRunningTests())->toBeTrue();
    } finally {
        // Restore the forced testing environment for the remaining tests.
        if ($serverValue !== null) {
            $_SERVER['APP_ENV'] = $serverValue;
        }

        if ($envValue !== null) {
            $_ENV['APP_ENV'] = $envValue;
        }

        if ($putValue !== false) {
            putenv('APP_ENV='.$putValue);
        }
    }
});

it('detects test runs from the runner entry point and not from artisan commands', function (): void {
    // Proven in fresh processes: inside a parallel worker the PHPUnit constant
    // is already defined, so "I am not a test run" can only be demonstrated
    // outside the suite. The artisan case is the deadlock guarantee — the
    // config:clear remedy must never classify as a test run.
    expect(probeIsRunningTestsEntry(['vendor/bin/pest', 'tests']))->toBe('true')
        ->and(probeIsRunningTestsEntry(['artisan', 'config:clear']))->toBe('false');
});

it('refuses to run tests against a cached configuration', function (): void {
    // Simulate bootstrap/cache/config.php having been loaded at boot.
    $this->app->instance('config_loaded_from_cache', true);

    expect(fn (): null => TestDatabaseSafety::enforce())
        ->toThrow(LogicException::class, 'Cached configuration detected during a test run. Run "php artisan config:clear" before running tests.');
});

it('still blocks APP_ENV=testing processes from a real database outside test runners', function (): void {
    // Regression guard for the historical protection: manual artisan commands
    // run with APP_ENV=testing keep the non-SQLite refusal even though they
    // are not test runners (characterization — this behavior predates the
    // runner detection). Proven in a fresh process: inside the suite the
    // PHPUnit bootstrap constant is always defined, so only a real artisan
    // subprocess can be a non-test-runner; an in-process argv shim would pass
    // for the wrong reason. The config cache points at a non-existent temp
    // path so the subprocess runs with a clean, cache-free boot.
    $probe = new Process(
        [PHP_BINARY, 'artisan', 'about'],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => sys_get_temp_dir().'/tallpbx-no-cache-'.bin2hex(random_bytes(8)).'.php',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'tallpbx',
            'LOG_CHANNEL' => 'null',
        ],
    );

    $probe->run();

    expect($probe->getExitCode())->not->toBe(0)
        ->and($probe->getOutput().$probe->getErrorOutput())
        ->toContain('Tests must use an in-memory SQLite database.');
});

it('keeps non-test commands bootable so they can clear a cached configuration', function (): void {
    // Real-process deadlock check: "php artisan config:clear" is the remedy for
    // a stale cache, so it must boot while that cache exists — even when the
    // cache pins a non-sqlite database — and delete it.
    $cachePath = sys_get_temp_dir().'/tallpbx-config-cache-'.bin2hex(random_bytes(8)).'.php';
    $scratchDatabase = sys_get_temp_dir().'/tallpbx-scratch-'.bin2hex(random_bytes(8)).'.sqlite';

    try {
        // Bake a cache whose database is a scratch file (loudly not :memory:).
        $generate = new Process(
            [PHP_BINARY, 'artisan', 'config:cache'],
            base_path(),
            [
                'APP_CONFIG_CACHE' => $cachePath,
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $scratchDatabase,
            ],
        );
        $generate->run();

        expect($generate->getExitCode())
            ->toBe(0, $generate->getOutput().$generate->getErrorOutput())
            ->and(is_file($cachePath))->toBeTrue();

        // The remedy must succeed and remove the cache; a guard that fired here
        // would deadlock its own repair.
        $clear = new Process(
            [PHP_BINARY, 'artisan', 'config:clear'],
            base_path(),
            ['APP_CONFIG_CACHE' => $cachePath],
        );
        $clear->run();

        expect($clear->getExitCode())
            ->toBe(0, $clear->getOutput().$clear->getErrorOutput())
            ->and(is_file($cachePath))->toBeFalse();
    } finally {
        @unlink($cachePath);
        @unlink($scratchDatabase);
    }
});

it('fails fast at boot when a test runner meets a cached configuration', function (): void {
    // Everything is written to disposable paths: the cache file and the
    // database it pins live in the temp directory so this test never creates
    // or deletes bootstrap/cache/config.php on a live host.
    $cachePath = sys_get_temp_dir().'/tallpbx-config-cache-'.bin2hex(random_bytes(8)).'.php';
    $scratchDatabase = sys_get_temp_dir().'/tallpbx-scratch-'.bin2hex(random_bytes(8)).'.sqlite';

    try {
        // Bake the cache from a non-testing environment with a scratch
        // database, proving the fail-fast does not depend on APP_ENV=testing.
        $generate = new Process(
            [PHP_BINARY, 'artisan', 'config:cache'],
            base_path(),
            [
                'APP_CONFIG_CACHE' => $cachePath,
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $scratchDatabase,
                'LOG_CHANNEL' => 'null',
            ],
        );
        $generate->run();

        expect($generate->getExitCode())
            ->toBe(0, $generate->getOutput().$generate->getErrorOutput())
            ->and(is_file($cachePath))->toBeTrue();

        // A bare Pest run that bypasses the auto-clearing test commands must
        // stop with the cached-configuration message before any test executes.
        $probe = new Process(
            [PHP_BINARY, base_path('vendor/bin/pest'), '--compact', 'tests/Feature/TestCommandEnvironmentProbeTest.php'],
            base_path(),
            [
                'APP_CONFIG_CACHE' => $cachePath,
                'LOG_CHANNEL' => 'null',
            ],
        );
        $probe->run();

        expect($probe->getExitCode())->not->toBe(0)
            ->and($probe->getOutput().$probe->getErrorOutput())
            ->toContain('Cached configuration detected during a test run.');
    } finally {
        @unlink($cachePath);
        @unlink($scratchDatabase);
    }
});

it('rejects a non-sqlite database in a test run even when APP_ENV is not testing', function (): void {
    // Run Pest without phpunit.xml's forced settings so the guard itself has to
    // catch a file-backed SQLite database while APP_ENV says "local".
    $scratchDatabase = sys_get_temp_dir().'/tallpbx-external-'.bin2hex(random_bytes(8)).'.sqlite';

    try {
        $probe = new Process(
            [
                PHP_BINARY,
                base_path('vendor/bin/pest'),
                '--no-configuration',
                '--bootstrap',
                'tests/Pest.php',
                '--compact',
                'tests/Feature/TestCommandEnvironmentProbeTest.php',
            ],
            base_path(),
            [
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $scratchDatabase,
                'LOG_CHANNEL' => 'null',
            ],
        );
        $probe->run();

        expect($probe->getExitCode())->not->toBe(0)
            ->and($probe->getOutput().$probe->getErrorOutput())
            ->toContain('Tests must use an in-memory SQLite database.');
    } finally {
        @unlink($scratchDatabase);
    }
});

/**
 * Ask TestDatabaseSafety::isRunningTests() inside a fresh, non-PHPUnit PHP
 * process that pretends to have been started with the given entry script.
 *
 * @param  array<int, string>  $argv  The argv the probe process should see
 */
function probeIsRunningTestsEntry(array $argv): string
{
    // The probe never boots Laravel: isRunningTests() only reads the process
    // identity, so a bare autoloader is enough to call it honestly.
    $script = sprintf(
        'require "vendor/autoload.php"; $_SERVER["argv"] = %s; var_export(\App\Support\TestDatabaseSafety::isRunningTests());',
        var_export($argv, true),
    );

    $process = new Process([PHP_BINARY, '-r', $script], base_path());
    $process->run();

    return trim($process->getOutput());
}

it('clears a stale configuration cache before running tests through php artisan test', function (): void {
    // php artisan test must remove a stale cache before spawning Pest — the
    // spawned run would otherwise be stopped by the boot-time fail-fast.
    $cachePath = sys_get_temp_dir().'/tallpbx-config-cache-'.bin2hex(random_bytes(8)).'.php';
    $scratchDatabase = sys_get_temp_dir().'/tallpbx-scratch-'.bin2hex(random_bytes(8)).'.sqlite';

    try {
        // Bake a cache whose database is a scratch file (loudly not :memory:).
        $generate = new Process(
            [PHP_BINARY, 'artisan', 'config:cache'],
            base_path(),
            [
                'APP_CONFIG_CACHE' => $cachePath,
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $scratchDatabase,
            ],
        );
        $generate->run();

        expect($generate->getExitCode())
            ->toBe(0, $generate->getOutput().$generate->getErrorOutput())
            ->and(is_file($cachePath))->toBeTrue();

        $run = new Process(
            [PHP_BINARY, 'artisan', 'test', '--compact', 'tests/Feature/TestCommandEnvironmentProbeTest.php'],
            base_path(),
            ['APP_CONFIG_CACHE' => $cachePath],
        );
        $run->run();

        expect($run->getExitCode())
            ->toBe(0, $run->getOutput().$run->getErrorOutput())
            ->and(is_file($cachePath))->toBeFalse();
    } finally {
        @unlink($cachePath);
        @unlink($scratchDatabase);
    }
});

it('clears a stale configuration cache before dispatching tests through php artisan app:test', function (): void {
    // app:test must clear the cache before dispatching its (long) suite; this
    // test observes the clear as it happens, then stops the run to stay fast.
    $cachePath = sys_get_temp_dir().'/tallpbx-config-cache-'.bin2hex(random_bytes(8)).'.php';
    $scratchDatabase = sys_get_temp_dir().'/tallpbx-scratch-'.bin2hex(random_bytes(8)).'.sqlite';

    try {
        // Bake a cache whose database is a scratch file (loudly not :memory:).
        $generate = new Process(
            [PHP_BINARY, 'artisan', 'config:cache'],
            base_path(),
            [
                'APP_CONFIG_CACHE' => $cachePath,
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $scratchDatabase,
            ],
        );
        $generate->run();

        expect($generate->getExitCode())
            ->toBe(0, $generate->getOutput().$generate->getErrorOutput())
            ->and(is_file($cachePath))->toBeTrue();

        $run = new Process(
            [PHP_BINARY, 'artisan', 'app:test', '--smoke', '--sequential'],
            base_path(),
            ['APP_CONFIG_CACHE' => $cachePath],
        );
        $run->start();

        // The clear happens before the smoke suite starts, so watching the
        // cache file vanish while the command still runs proves the ordering.
        $deadline = microtime(true) + 30;
        while (is_file($cachePath) && $run->isRunning() && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $clearedWhileRunning = ! is_file($cachePath);
        $run->stop(3);

        expect($clearedWhileRunning)
            ->toBeTrue('php artisan app:test left the stale configuration cache in place');
    } finally {
        @unlink($cachePath);
        @unlink($scratchDatabase);
    }
});

it('runs tests against the dedicated Redis test workspaces', function (): void {
    // Tripwire for the Redis isolation: production security state (ban
    // counters, scanner dedupe) lives in Redis DB 0 under the production
    // prefix, so tests must resolve to their own databases and prefix. If a
    // configuration shape (e.g. a URL-style override) ever defeats the
    // isolation, this fails loudly instead of silently sharing state.
    expect((string) config('database.redis.default.database'))->toBe('15')
        ->and((string) config('database.redis.cache.database'))->toBe('14')
        ->and((string) config('database.redis.session.database'))->toBe('13')
        ->and((string) config('session.connection'))->toBe('session')
        ->and((string) config('database.redis.options.prefix'))->toBe('tallpbx-test-');
});

it('compiles rendered views into a test-only folder away from the live panel', function (): void {
    // Root-run tests used to poison the live panel's compiled-view folder
    // (storage/framework/views) with root-owned files, breaking the panel
    // with "touch(): Utime failed". Both config('view.compiled') and the
    // already-resolved BladeCompiler singleton must point at the test-only
    // folder so sequential/targeted test runs do not leak into the live path.
    $compiled = (string) config('view.compiled');
    $liveViews = (string) realpath(storage_path('framework/views'));
    $compilerCompiledPath = (string) app('blade.compiler')->getCompiledPath('welcome.blade.php');

    expect($compiled)->not->toBe($liveViews)
        ->and($compiled)->toContain('testing-views')
        ->and(is_dir($compiled))->toBeTrue()
        ->and($compilerCompiledPath)->toStartWith($compiled)
        ->and($compilerCompiledPath)->not->toStartWith($liveViews);

    if (app()->bound('livewire.compiler')) {
        expect((string) app('livewire.compiler')->cacheManager->cacheDirectory)
            ->toStartWith($compiled);
    }
});

it('enforces database safety during service provider registration before later providers run', function (): void {
    // ModuleServiceProvider::register() runs before any boot() hook and can
    // write to the modules table when a module has unmet requirements, so
    // AppServiceProvider::register() must enforce database safety first.
    $this->app->instance('config_loaded_from_cache', true);

    expect(fn () => (new AppServiceProvider($this->app))->register())
        ->toThrow(LogicException::class, 'Cached configuration detected during a test run. Run "php artisan config:clear" before running tests.');
});

it('keeps the test-only compiled view folder out of git', function (): void {
    // The test-only folder fills up with generated scratch views during test
    // runs (parallel workers create per-worker test_N/ subfolders). These are
    // disposable artifacts and must never surface as uncommitted files in
    // git status, so the ignore entry is guarded here against regression.
    expect(file_get_contents(base_path('.gitignore')))
        ->toContain('/storage/framework/testing-views');
});
