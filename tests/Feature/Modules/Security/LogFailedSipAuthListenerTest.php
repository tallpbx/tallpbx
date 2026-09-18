<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Events\FreeSwitch\CustomEvent;
use App\Events\FreeSwitch\SofiaFailedAuth;
use App\Services\FreeSwitchServiceInterface;
use Database\Seeders\SecurityServiceSeeder;
use Mockery;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityIncidentServiceInterface;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\SecurityIncidentService;

/**
 * Feature tests for the FreeSWITCH ESL SIP authentication failure listener (SIP Vector).
 *
 * Verifies that Sofia SIP failed_auth events emitted by FreeSWITCH ESL
 * are captured, that offending client IPs and SIP metadata are extracted,
 * and that security incidents are recorded to enforce automated kernel bans.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
});

it('captures sofia::failed_auth CustomEvent and forwards to SecurityIncidentService', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldReceive('recordFailure')
        ->once()
        ->with(
            '198.51.100.77',
            'sip_auth',
            'SIP User: 1002, Realm: sip.tallpbx.com, Profile: internal'
        );

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $event = new CustomEvent(
        eventName: 'CUSTOM',
        headers: [
            'Event-Name' => 'CUSTOM',
            'Event-Subclass' => 'sofia::failed_auth',
            'network-ip' => '198.51.100.77',
            'user' => '1002',
            'realm' => 'sip.tallpbx.com',
            'profile-name' => 'internal',
        ],
        body: '',
    );

    event($event);
});

it('captures SofiaFailedAuth typed event and forwards to SecurityIncidentService', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldReceive('recordFailure')
        ->once()
        ->with(
            '203.0.113.55',
            'sip_auth',
            'SIP User: admin, Realm: 192.168.1.1, Profile: external'
        );

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $event = new SofiaFailedAuth(
        eventName: 'CUSTOM',
        headers: [
            'Event-Name' => 'CUSTOM',
            'Event-Subclass' => 'sofia::failed_auth',
            'network-ip' => '203.0.113.55',
            'user' => 'admin',
            'realm' => '192.168.1.1',
            'profile-name' => 'external',
        ],
        body: '',
    );

    expect($event->networkIp())->toBe('203.0.113.55')
        ->and($event->user())->toBe('admin')
        ->and($event->realm())->toBe('192.168.1.1')
        ->and($event->profileName())->toBe('external');

    event($event);
});

it('ignores other FreeSWITCH custom events', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldNotReceive('recordFailure');

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $event = new CustomEvent(
        eventName: 'CUSTOM',
        headers: [
            'Event-Name' => 'CUSTOM',
            'Event-Subclass' => 'conference::maintenance',
            'Action' => 'add-member',
        ],
        body: '',
    );

    event($event);
});

it('handles FreeSwitchListenCommand ESL dispatch of sofia::failed_auth', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldReceive('recordFailure')
        ->once()
        ->with(
            '185.220.101.5',
            'sip_auth',
            'SIP User: 1001, Realm: sip.tallpbx.com, Profile: internal'
        );

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $mockEsl = Mockery::mock(FreeSwitchServiceInterface::class);
    $mockEsl->shouldReceive('connect')->once()->andReturn(true);
    $mockEsl->shouldReceive('subscribeToEvents')->once();
    $mockEsl->shouldReceive('isConnected')->once()->andReturn(true);
    $mockEsl->shouldReceive('recvEvent')->once()->andReturn([
        'event_name' => 'CUSTOM',
        'headers' => [
            'Event-Name' => 'CUSTOM',
            'Event-Subclass' => 'sofia::failed_auth',
            'network-ip' => '185.220.101.5',
            'user' => '1001',
            'realm' => 'sip.tallpbx.com',
            'profile-name' => 'internal',
        ],
        'body' => '',
    ]);
    $mockEsl->shouldReceive('disconnect')->once();

    $this->app->instance(FreeSwitchServiceInterface::class, $mockEsl);

    $this->artisan('freeswitch:listen', ['--once' => true])
        ->assertSuccessful();
});

it('exempts whitelisted IP from SIP failure bans', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '64.2.142.18',
        'description' => 'Carrier SIP trunk endpoint',
    ]);

    $banService = Mockery::mock(SecurityBanServiceInterface::class);
    $banService->shouldNotReceive('ban');

    $incidentService = new SecurityIncidentService($banService);

    // 10 failed SIP auth attempts from carrier trunk IP
    for ($i = 0; $i < 10; $i++) {
        $incidentService->recordFailure('64.2.142.18', 'sip_auth', 'Failed password attempt');
    }

    expect($incidentService->isWhitelisted('64.2.142.18'))->toBeTrue();
});

it('triggers ban when SIP auth failures exceed max_retry threshold', function (): void {
    SecuritySetting::set('max_retry', 4);
    SecuritySetting::set('find_time', 600);
    SecuritySetting::set('ban_time', 3600);

    $banService = Mockery::mock(SecurityBanServiceInterface::class);
    $banService->shouldReceive('ban')
        ->once()
        ->with(
            '185.220.101.99',
            'sip_auth',
            Mockery::type('string'),
            3600
        );

    $incidentService = new SecurityIncidentService($banService);

    for ($i = 1; $i <= 3; $i++) {
        $incidentService->recordFailure('185.220.101.99', 'sip_auth', "Attempt {$i}");
    }

    // Attempt 4 hits threshold and invokes ban
    $incidentService->recordFailure('185.220.101.99', 'sip_auth', 'Attempt 4');
});
