<?php

declare(strict_types=1);

use App\Services\SystemHealth;

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
