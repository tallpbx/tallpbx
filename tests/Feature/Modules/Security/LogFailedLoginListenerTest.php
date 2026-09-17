<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityIncidentServiceInterface;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\SecurityIncidentService;

/**
 * Feature tests for the in-process authentication failure listener (Web UI Vector).
 *
 * Verifies that Failed authentication events trigger the security incident listener,
 * that incident recording increments Redis failure counters, and that threshold limits
 * trigger ban delegation while whitelisted IPs remain protected.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
    // Clear test redis keys if any
    try {
        $keys = Redis::keys('tallpbx:security:attempts:*');
        if (! empty($keys)) {
            Redis::del($keys);
        }
    } catch (\Throwable) {
        // Ignore if Redis in-memory mock or non-connected in test environment
    }
});

it('registers LogFailedLoginListener for Illuminate\Auth\Events\Failed', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldReceive('recordFailure')
        ->once()
        ->with('198.51.100.25', 'web_auth', Mockery::type('string'));

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    // Set client IP on the active request
    request()->server->set('REMOTE_ADDR', '198.51.100.25');

    $admin = Admin::factory()->make(['email' => 'attacker@test.com']);
    event(new Failed(
        guard: 'admin',
        user: $admin,
        credentials: ['email' => 'attacker@test.com', 'password' => 'wrong-pass']
    ));
});

it('captures failed logins via post to /panel/login', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldReceive('recordFailure')
        ->atLeast()->once()
        ->with('203.0.113.88', 'web_auth', Mockery::type('string'));

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.88'])
        ->post('/panel/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'badpassword',
        ]);
});

it('exempts whitelisted IP addresses from failure tracking', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '192.168.10.50',
        'description' => 'Office trusted workstation',
    ]);

    $banService = Mockery::mock(SecurityBanServiceInterface::class);
    $banService->shouldNotReceive('ban');

    $incidentService = new SecurityIncidentService($banService);

    // Whitelisted IP fails 10 times, but never triggers ban
    for ($i = 0; $i < 10; $i++) {
        $incidentService->recordFailure('192.168.10.50', 'web_auth', 'Failed password attempt');
    }

    expect($incidentService->isWhitelisted('192.168.10.50'))->toBeTrue();
});

it('exempts whitelisted CIDR subnets from failure tracking', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '10.50.0.0/16',
        'description' => 'Internal office subnet',
    ]);

    $banService = Mockery::mock(SecurityBanServiceInterface::class);
    $banService->shouldNotReceive('ban');

    $incidentService = new SecurityIncidentService($banService);

    expect($incidentService->isWhitelisted('10.50.12.34'))->toBeTrue()
        ->and($incidentService->isWhitelisted('10.51.0.1'))->toBeFalse();
});

it('triggers ban when failures exceed max_retry threshold', function (): void {
    SecuritySetting::set('max_retry', 3);
    SecuritySetting::set('find_time', 300);
    SecuritySetting::set('ban_time', 1800);

    $banService = Mockery::mock(SecurityBanServiceInterface::class);
    $banService->shouldReceive('ban')
        ->once()
        ->with(
            '198.51.100.99',
            'web_auth',
            Mockery::type('string'),
            1800
        );

    $incidentService = new SecurityIncidentService($banService);

    // Attempts 1 and 2 increment counter
    $incidentService->recordFailure('198.51.100.99', 'web_auth', 'Attempt 1');
    $incidentService->recordFailure('198.51.100.99', 'web_auth', 'Attempt 2');

    // Attempt 3 hits max_retry threshold of 3 and triggers ban
    $incidentService->recordFailure('198.51.100.99', 'web_auth', 'Attempt 3');
});

it('respects protect_web setting to disable web login tracking', function (): void {
    SecuritySetting::set('protect_web', false);

    $banService = Mockery::mock(SecurityBanServiceInterface::class);
    $banService->shouldNotReceive('ban');

    $incidentService = new SecurityIncidentService($banService);

    expect($incidentService->isVectorProtected('web_auth'))->toBeFalse();

    // 10 failures recorded while protect_web is false
    for ($i = 0; $i < 10; $i++) {
        $incidentService->recordFailure('198.51.100.77', 'web_auth', 'Attempt');
    }
});
