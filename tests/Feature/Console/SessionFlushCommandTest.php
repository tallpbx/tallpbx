<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    // Preserve default session configuration
    $this->originalDriver = config('session.driver');
    $this->originalConnection = config('session.connection');
    $this->originalFiles = config('session.files');
    $this->originalRedisDefault = config('database.redis.default');
    $this->originalRedisCache = config('database.redis.cache');
    $this->originalRedisSession = config('database.redis.session');
});

afterEach(function (): void {
    config([
        'session.driver' => $this->originalDriver,
        'session.connection' => $this->originalConnection,
        'session.files' => $this->originalFiles,
        'database.redis.default' => $this->originalRedisDefault,
        'database.redis.cache' => $this->originalRedisCache,
        'database.redis.session' => $this->originalRedisSession,
    ]);
});

it('cancels flushing when confirmation is declined', function (): void {
    config(['session.driver' => 'array']);

    $this->artisan('session:flush')
        ->expectsConfirmation('This will invalidate all active HTTP sessions across all tenants and administrators (driver: array (in-memory)). Do you wish to continue?', 'no')
        ->expectsOutputToContain('Session flush cancelled.')
        ->assertSuccessful();
});

it('flushes session store when confirmation is accepted', function (): void {
    config(['session.driver' => 'array']);

    $this->artisan('session:flush')
        ->expectsConfirmation('This will invalidate all active HTTP sessions across all tenants and administrators (driver: array (in-memory)). Do you wish to continue?', 'yes')
        ->expectsOutputToContain('Flushed in-memory array session store.')
        ->assertSuccessful();
});

it('discloses the exact Redis connection, host, port, and database index in the confirmation prompt before flushing', function (): void {
    config([
        'session.driver' => 'redis',
        'session.connection' => 'session',
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '0'],
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
        'database.redis.session' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '13'],
    ]);

    $this->artisan('session:flush')
        ->expectsConfirmation(
            'This will invalidate all active HTTP sessions across all tenants and administrators (driver: redis, connection: [session], endpoint: [127.0.0.1:6379], database: [13]). Do you wish to continue?',
            'no'
        )
        ->expectsOutputToContain('Session flush cancelled.')
        ->assertSuccessful();
});

it('discloses database connection and table in confirmation prompt', function (): void {
    config([
        'session.driver' => 'database',
        'session.connection' => 'custom_db',
        'session.table' => 'app_sessions',
    ]);

    $this->artisan('session:flush')
        ->expectsConfirmation(
            'This will invalidate all active HTTP sessions across all tenants and administrators (driver: database, connection: [custom_db], table: [app_sessions]). Do you wish to continue?',
            'no'
        )
        ->expectsOutputToContain('Session flush cancelled.')
        ->assertSuccessful();
});

it('discloses file session path in confirmation prompt', function (): void {
    config([
        'session.driver' => 'file',
        'session.files' => '/tmp/tallpbx-sessions',
    ]);

    $this->artisan('session:flush')
        ->expectsConfirmation(
            'This will invalidate all active HTTP sessions across all tenants and administrators (driver: file, path: [/tmp/tallpbx-sessions]). Do you wish to continue?',
            'no'
        )
        ->expectsOutputToContain('Session flush cancelled.')
        ->assertSuccessful();
});

it('discloses cookie driver notice in confirmation prompt', function (): void {
    config([
        'session.driver' => 'cookie',
    ]);

    $this->artisan('session:flush')
        ->expectsConfirmation(
            'This will invalidate all active HTTP sessions across all tenants and administrators (driver: cookie (client-side only; no server store will be modified)). Do you wish to continue?',
            'no'
        )
        ->expectsOutputToContain('Session flush cancelled.')
        ->assertSuccessful();
});

it('refuses to run FLUSHDB without --force when the session Redis database collides with default or cache', function (string $collidingDb, string $expectedRole): void {
    Redis::shouldReceive('connection')->never();

    config([
        'session.driver' => 'redis',
        'session.connection' => 'session',
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '0'],
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
        'database.redis.session' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => $collidingDb],
    ]);

    $this->artisan('session:flush')
        ->expectsOutputToContain("shares database [{$collidingDb}] on [127.0.0.1:6379] with Redis [{$expectedRole}] connection")
        ->assertFailed();
})->with([
    'collides with default DB 0' => ['0', 'default'],
    'collides with cache DB 1' => ['1', 'cache'],
]);

it('refuses to run FLUSHDB without --force when session.connection is literally default or cache', function (string $role): void {
    Redis::shouldReceive('connection')->never();

    config([
        'session.driver' => 'redis',
        'session.connection' => $role,
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '0'],
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
    ]);

    $this->artisan('session:flush')
        ->expectsOutputToContain("with Redis [{$role}] connection")
        ->assertFailed();
})->with([
    'connection named default' => ['default'],
    'connection named cache' => ['cache'],
]);

it('allows overriding the Redis database collision guard when --force is explicitly passed', function (): void {
    $redisConn = \Mockery::mock();
    $redisConn->shouldReceive('flushdb')->once()->andReturnTrue();
    Redis::shouldReceive('connection')->once()->with('session')->andReturn($redisConn);

    config([
        'session.driver' => 'redis',
        'session.connection' => 'session',
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '0'],
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
        'database.redis.session' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
    ]);

    $this->artisan('session:flush', ['--force' => true])
        ->expectsOutputToContain('shares database [1]')
        ->expectsOutputToContain('Flushed Redis session store')
        ->assertSuccessful();
});

it('flushes redis session store when force flag is provided', function (): void {
    config(['session.driver' => 'redis', 'session.connection' => 'session']);

    $this->artisan('session:flush', ['--force' => true])
        ->expectsOutputToContain('Flushed Redis session store')
        ->assertSuccessful();
});

it('supports session:clear alias for session:flush', function (): void {
    config(['session.driver' => 'redis', 'session.connection' => 'session']);

    $this->artisan('session:clear', ['--force' => true])
        ->expectsOutputToContain('Flushed Redis session store')
        ->assertSuccessful();
});

it('flushes file session store preserving gitignore', function (): void {
    $filesystem = app(Filesystem::class);
    $tempDir = storage_path('framework/testing/sessions_'.uniqid());
    $filesystem->makeDirectory($tempDir, 0755, true);

    $gitignore = $tempDir.'/.gitignore';
    $session1 = $tempDir.'/sess_abc123';
    $session2 = $tempDir.'/sess_def456';

    file_put_contents($gitignore, "*\n!.gitignore\n");
    file_put_contents($session1, 'payload1');
    file_put_contents($session2, 'payload2');

    config([
        'session.driver' => 'file',
        'session.files' => $tempDir,
    ]);

    $this->artisan('session:flush', ['--force' => true])
        ->expectsOutputToContain('Cleared 2 session file(s)')
        ->assertSuccessful();

    expect(file_exists($gitignore))->toBeTrue()
        ->and(file_exists($session1))->toBeFalse()
        ->and(file_exists($session2))->toBeFalse();

    $filesystem->deleteDirectory($tempDir);
});

it('flushes database session store by truncating table', function (): void {
    DB::table('sessions')->truncate();

    DB::table('sessions')->insert([
        'id' => 'test-session-id',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    expect(DB::table('sessions')->count())->toBe(1);

    config([
        'session.driver' => 'database',
        'session.connection' => null,
        'session.table' => 'sessions',
    ]);

    $this->artisan('session:flush', ['--force' => true])
        ->expectsOutputToContain('Truncated sessions table [sessions]')
        ->assertSuccessful();

    expect(DB::table('sessions')->count())->toBe(0);
});

it('handles cookie session driver with an informational warning', function (): void {
    config(['session.driver' => 'cookie']);

    $this->artisan('session:flush', ['--force' => true])
        ->expectsOutputToContain('Cookie session driver stores session data client-side in browser cookies')
        ->assertSuccessful();
});

it('handles in-memory array session driver', function (): void {
    config(['session.driver' => 'array']);

    $this->artisan('session:flush', ['--force' => true])
        ->expectsOutputToContain('Flushed in-memory array session store.')
        ->assertSuccessful();
});
