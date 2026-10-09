<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Mockery;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecuritySetting;

/**
 * Feature tests for the security:observe console command.
 */
it('runs security:observe when observe mode is disabled and reports enforcing status', function (): void {
    SecuritySetting::set('firewall_observe_mode', false);

    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('status')
        ->once()
        ->andReturn("table inet tallpbx_filter {\n chain input { type filter hook input priority -10; policy drop; }\n}");
    $mockExecutor->shouldReceive('observeEvents')
        ->once()
        ->with(20)
        ->andReturn([]);

    $this->app->instance(SecurityExecutorInterface::class, $mockExecutor);

    $this->artisan('security:observe')
        ->expectsOutputToContain('Global Observe Mode')
        ->expectsOutputToContain('STATUS: DISABLED')
        ->expectsOutputToContain('TOTAL OBSERVED WOULD-BE DROPS')
        ->assertSuccessful();
});

it('runs security:observe when observe mode is active and displays hit counters and events', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);

    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('status')
        ->once()
        ->andReturn("table inet tallpbx_filter {\n chain input {\n ip saddr @banned_ips counter packets 42 bytes 2100 log prefix \"tallpbx-observe:bans \" limit rate 100/minute\n }\n}");
    $mockExecutor->shouldReceive('observeEvents')
        ->once()
        ->with(20)
        ->andReturn([
            [
                'timestamp' => '2026-10-08 22:44:33',
                'raw_timestamp' => '2026-10-08T22:44:33-07:00',
                'stage' => 'bans',
                'stage_label' => 'Banned Attacker',
                'interface' => 'veth-host',
                'src_ip' => '10.254.254.2',
                'dst_ip' => '10.254.254.1',
                'proto' => 'UDP',
                'spt' => '43032',
                'dpt' => '69',
                'raw' => 'raw-line',
            ],
        ]);

    $this->app->instance(SecurityExecutorInterface::class, $mockExecutor);

    $this->artisan('security:observe')
        ->expectsOutputToContain('STATUS: ACTIVE')
        ->expectsOutputToContain('Banned Attackers')
        ->expectsOutputToContain('42')
        ->expectsOutputToContain('10.254.254.2')
        ->assertSuccessful();
});
