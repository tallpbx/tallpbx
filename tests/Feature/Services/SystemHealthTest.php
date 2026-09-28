<?php

declare(strict_types=1);

use App\Services\SystemHealth;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    // Ensure the service can be instantiated even in test environments
    // where system commands may not be available.
});

it('returns disk usage information', function (): void {
    $health = app(SystemHealth::class);
    $disk = $health->diskUsage();

    expect($disk)->toHaveKeys(['total_gb', 'used_gb', 'free_gb', 'percent_used', 'mount_point']);
    expect($disk['total_gb'])->toBeFloat();
    expect($disk['percent_used'])->toBeFloat()->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
});

it('returns memory usage information', function (): void {
    $health = app(SystemHealth::class);
    $memory = $health->memoryUsage();

    expect($memory)->toHaveKeys(['total_gb', 'used_gb', 'free_gb', 'percent_used']);
    expect($memory['total_gb'])->toBeFloat();
    expect($memory['percent_used'])->toBeFloat();
});

it('returns service status list', function (): void {
    $health = app(SystemHealth::class);
    $services = $health->serviceStatus();

    expect($services)->toBeArray();
    // At minimum, the array should contain the keys for each monitored service
    expect($services)->toHaveKeys(['nginx', 'mariadb', 'php8.5-fpm', 'redis-server', 'freeswitch']);
    foreach ($services as $service) {
        expect($service)->toHaveKeys(['name', 'running', 'status_text']);
    }
});

it('returns FreeSWITCH status', function (): void {
    $health = app(SystemHealth::class);
    $fs = $health->freeswitchStatus();

    expect($fs)->toHaveKeys(['running', 'uptime', 'sessions', 'calls_per_second']);
    expect($fs['running'])->toBeBool();
});

it('returns certificate expiry information when available', function (): void {
    $health = app(SystemHealth::class);
    $cert = $health->certificateExpiry();

    if ($cert !== null) {
        expect($cert)->toHaveKeys(['domain', 'expires_at', 'days_remaining', 'issuer']);
    } else {
        expect($cert)->toBeNull();
    }
});

it('returns a complete health summary', function (): void {
    $health = app(SystemHealth::class);
    $summary = $health->summary();

    expect($summary)->toHaveKeys(['disk', 'memory', 'services', 'freeswitch', 'certificate']);
});

it('serves the summary from the cache while the 30 second window is open', function (): void {
    // Prefill the cache the way an earlier dashboard refresh would have;
    // the service must reuse it instead of spawning system processes again.
    $sentinel = [
        'disk' => ['total_gb' => 42.0, 'used_gb' => 21.0, 'free_gb' => 21.0, 'percent_used' => 50.0, 'mount_point' => '/'],
        'memory' => ['total_gb' => 8.0, 'used_gb' => 4.0, 'free_gb' => 4.0, 'percent_used' => 50.0],
        'services' => [],
        'freeswitch' => ['running' => false, 'uptime' => 'N/A', 'sessions' => 0, 'calls_per_second' => 0],
        'certificate' => null,
    ];
    Cache::put('system.health.summary', $sentinel, 30);

    expect(app(SystemHealth::class)->summary())->toBe($sentinel);
});

it('never passes the domain through a shell when fetching a certificate', function (): void {
    // Run from a scratch directory so a relative marker file would land
    // there if the domain reached a shell interpreter.
    $workDir = sys_get_temp_dir();
    $marker = 'tallpbx-injection-'.bin2hex(random_bytes(6));
    $previousDir = getcwd();
    chdir($workDir);

    try {
        // Semicolon plus dollar-IFS field splitting: if this value were
        // interpolated into a shell command, "touch <marker>" would run.
        // An argument-array process can only ever treat it as a hostname.
        config(['app.url' => 'http://x;touch${IFS}'.$marker.';']);

        app(SystemHealth::class)->certificateExpiry();

        expect(file_exists($workDir.'/'.$marker))->toBeFalse();
    } finally {
        chdir($previousDir);
        @unlink($workDir.'/'.$marker);
    }
});
