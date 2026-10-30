<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    // Preserve default session configuration
    $this->originalDriver = config('session.driver');
    $this->originalConnection = config('session.connection');
    $this->originalFiles = config('session.files');
});

afterEach(function (): void {
    config([
        'session.driver' => $this->originalDriver,
        'session.connection' => $this->originalConnection,
        'session.files' => $this->originalFiles,
    ]);
});

it('cancels flushing when confirmation is declined', function (): void {
    $this->artisan('session:flush')
        ->expectsConfirmation('This will invalidate all active HTTP sessions across all tenants and administrators. Do you wish to continue?', 'no')
        ->expectsOutputToContain('Session flush cancelled.')
        ->assertSuccessful();
});

it('flushes session store when confirmation is accepted', function (): void {
    config(['session.driver' => 'array']);

    $this->artisan('session:flush')
        ->expectsConfirmation('This will invalidate all active HTTP sessions across all tenants and administrators. Do you wish to continue?', 'yes')
        ->expectsOutputToContain('Flushed in-memory array session store.')
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
