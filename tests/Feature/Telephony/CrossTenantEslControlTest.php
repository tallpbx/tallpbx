<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Tenant;
use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantManager;
use Database\Seeders\AdminSeeder;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Modules\ActiveConferences\Livewire\ActiveConferencesList;
use Modules\CallCenterActive\Livewire\CallCenterActiveList;
use Modules\Conferences\Models\Conference;
use Modules\Extensions\Models\Extension;

/**
 * Extract the Livewire snapshot JSON and decoded array for a specific component.
 *
 * @return array{0: string, 1: array{data: array<string, mixed>, memo: array<string, mixed>, checksum?: string}}
 */
function extractComponentSnapshot(string $html, string $nameSnippet): array
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
    foreach ($matches[1] as $snapEncoded) {
        $candidateJson = html_entity_decode($snapEncoded, ENT_QUOTES | ENT_HTML5);
        /** @var array{data: array<string, mixed>, memo: array<string, mixed>, checksum?: string} $candidate */
        $candidate = json_decode($candidateJson, true);
        if (str_contains($candidate['memo']['name'] ?? '', $nameSnippet)) {
            return [$candidateJson, $candidate];
        }
    }

    throw new \RuntimeException("Snapshot for component [{$nameSnippet}] was not found in response.");
}

beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($superAdminGroup->id);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
});

it('prevents tenant user from muting or kicking conference members of another tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    Conference::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => '3001@tenant-a.test',
        'enabled' => true,
    ]);
    Conference::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => '3002@tenant-b.test',
        'enabled' => true,
    ]);

    $userA = grantTenantUserPermissions($tenantA, [
        'active-conferences.view',
        'active-conferences.mute',
        'active-conferences.kick',
    ]);

    $rawConferences = "Conference 3001@tenant-a.test (members: 1) running\n".
        "1;6ba7b810-9dad-11d1-80b4-00c04fd430c1;1001;3001@tenant-a.test;listen;talk\n".
        "Conference 3002@tenant-b.test (members: 1) running\n".
        "1;6ba7b810-9dad-11d1-80b4-00c04fd430c2;2001;3002@tenant-b.test;listen;talk\n";

    $fs = \Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->andReturn(true);
    $fs->shouldReceive('api')->with('conference list')->andReturn($rawConferences);
    $fs->shouldNotReceive('api')->with(\Mockery::pattern('/conference 3002@tenant-b\.test (mute|kick)/'));
    $fs->shouldNotReceive('bgapi')->with(\Mockery::pattern('/conference 3002@tenant-b\.test/'));
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($userA, 'web')
        ->test(ActiveConferencesList::class)
        ->call('muteMember', '3002@tenant-b.test', '1')
        ->assertSet('actionError', 'Target not found in active tenant.')
        ->call('kickMember', '3002@tenant-b.test', '1')
        ->assertSet('actionError', 'Target not found in active tenant.');
});

it('prevents tenant user from controlling call center agents of another tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    Extension::factory()->create([
        'tenant_id' => $tenantA->id,
        'extension_number' => '1001',
        'enabled' => true,
    ]);
    Extension::factory()->create([
        'tenant_id' => $tenantB->id,
        'extension_number' => '2001',
        'enabled' => true,
    ]);

    $userA = grantTenantUserPermissions($tenantA, [
        'call-center-active.view',
        'call-center-active.control',
    ]);

    $rawAgents = "agent|status|state|last_state_change\n".
        "1001@tenant-a.test|Available|Waiting|0\n".
        "2001@tenant-b.test|Available|Waiting|0\n";

    $fs = \Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->andReturn(true);
    $fs->shouldReceive('api')->with('callcenter_config queue list')->andReturn("name|strategy\n");
    $fs->shouldReceive('api')->with('callcenter_config agent list')->andReturn($rawAgents);
    $fs->shouldNotReceive('api')->with(\Mockery::pattern('/callcenter_config agent (pause|unpause|logout) 2001@tenant-b\.test/'));
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($userA, 'web')
        ->test(CallCenterActiveList::class)
        ->call('pauseAgent', '2001@tenant-b.test')
        ->assertSet('actionError', 'Target not found in active tenant.')
        ->call('unpauseAgent', '2001@tenant-b.test')
        ->assertSet('actionError', 'Target not found in active tenant.')
        ->call('logoutAgent', '2001@tenant-b.test')
        ->assertSet('actionError', 'Target not found in active tenant.');
});

it('allows tenant user to control own conference and agent targets', function (): void {
    $tenantA = Tenant::factory()->create();

    Conference::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => '3001@tenant-a.test',
        'enabled' => true,
    ]);
    Extension::factory()->create([
        'tenant_id' => $tenantA->id,
        'extension_number' => '1001',
        'enabled' => true,
    ]);

    $userA = grantTenantUserPermissions($tenantA, [
        'active-conferences.view',
        'active-conferences.mute',
        'active-conferences.kick',
        'call-center-active.view',
        'call-center-active.control',
    ]);

    $rawConferences = "Conference 3001@tenant-a.test (members: 1) running\n".
        "1;6ba7b810-9dad-11d1-80b4-00c04fd430c1;1001;3001@tenant-a.test;listen;talk\n";
    $rawAgents = "agent|status|state|last_state_change\n".
        "1001@tenant-a.test|Available|Waiting|0\n";

    $fs = \Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->andReturn(true);
    $fs->shouldReceive('api')->with('conference list')->andReturn($rawConferences);
    $fs->shouldReceive('api')->with('conference 3001@tenant-a.test mute 1 on')->once()->andReturn('+OK');
    $fs->shouldReceive('api')->with('conference 3001@tenant-a.test mute 1 off')->once()->andReturn('+OK');
    $fs->shouldReceive('api')->with('conference 3001@tenant-a.test kick 1')->once()->andReturn('+OK');

    $fs->shouldReceive('api')->with('callcenter_config queue list')->andReturn("name|strategy\n");
    $fs->shouldReceive('api')->with('callcenter_config agent list')->andReturn($rawAgents);
    $fs->shouldReceive('api')->with('callcenter_config agent pause 1001@tenant-a.test')->once()->andReturn('+OK');
    $fs->shouldReceive('api')->with('callcenter_config agent unpause 1001@tenant-a.test')->once()->andReturn('+OK');
    $fs->shouldReceive('api')->with('callcenter_config agent logout 1001@tenant-a.test')->once()->andReturn('+OK');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($userA, 'web')
        ->test(ActiveConferencesList::class)
        ->call('muteMember', '3001@tenant-a.test', '1')
        ->assertSet('actionMessage', 'Command accepted.')
        ->call('unmuteMember', '3001@tenant-a.test', '1')
        ->assertSet('actionMessage', 'Command accepted.')
        ->call('kickMember', '3001@tenant-a.test', '1')
        ->assertSet('actionMessage', 'Command accepted.');

    Livewire::actingAs($userA, 'web')
        ->test(CallCenterActiveList::class)
        ->call('pauseAgent', '1001@tenant-a.test')
        ->assertSet('actionMessage', 'Command accepted.')
        ->call('unpauseAgent', '1001@tenant-a.test')
        ->assertSet('actionMessage', 'Command accepted.')
        ->call('logoutAgent', '1001@tenant-a.test')
        ->assertSet('actionMessage', 'Command accepted.');
});

it('prevents cross-tenant hangup and transfer on ActiveCallsList even when snapshot calls property is tampered', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = grantTenantUserPermissions($tenantA, [
        'active-calls.view',
        'active-calls.hangup',
        'active-calls.transfer',
    ]);

    $uuidA = '11111111-1111-1111-1111-111111111111';
    $uuidB = '22222222-2222-2222-2222-222222222222';

    $channelsData = [
        'header' => ['uuid', 'direction'],
        'rows' => [
            [
                'uuid' => $uuidA,
                'cid_num' => '1001',
                'cid_name' => 'Alice',
                'dest' => '2001',
                'state' => 'CS_EXECUTE',
                'created_epoch' => (string) (time() - 30),
                'context' => 'tenant_'.$tenantA->id.'_internal',
            ],
            [
                'uuid' => $uuidB,
                'cid_num' => '2001',
                'cid_name' => 'Bob',
                'dest' => '3001',
                'state' => 'CS_EXECUTE',
                'created_epoch' => (string) (time() - 15),
                'context' => 'tenant_'.$tenantB->id.'_internal',
            ],
        ],
        'row_count' => 2,
    ];
    $channelsJson = (string) json_encode($channelsData);

    $fs = \Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->andReturn(true);
    $fs->shouldReceive('api')->with('show channels as json')->andReturn($channelsJson);
    $fs->shouldNotReceive('api')->with('uuid_kill '.$uuidB);
    $fs->shouldNotReceive('api')->with('uuid_transfer '.$uuidB.' 2000');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    // Render component to get valid snapshot
    $renderResponse = $this->actingAs($userA, 'web')->get(route('panel.active-calls.index'));
    $renderResponse->assertOk();

    [, $snapshot] = extractComponentSnapshot((string) $renderResponse->getContent(), 'active-calls');

    // Tamper snapshot data to inject Tenant B's UUID into calls
    $snapshot['data']['calls'] = [
        [
            'uuid' => $uuidB,
            'caller_id' => '2001',
            'caller_id_name' => 'Bob',
            'destination' => '3001',
            'duration' => 15,
            'state' => 'CS_EXECUTE',
            'context' => 'tenant_'.$tenantB->id.'_internal',
        ],
    ];
    unset($snapshot['checksum']);
    $snapshot['checksum'] = Checksum::generate($snapshot);
    $tamperedSnapshotString = (string) json_encode($snapshot);

    // Attempt hangupCall on Tenant B UUID
    $hangupResponse = $this->actingAs($userA, 'web')
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(route('panel.livewire.update'), [
            'components' => [
                [
                    'snapshot' => $tamperedSnapshotString,
                    'updates' => [],
                    'calls' => [
                        [
                            'path' => '',
                            'method' => 'hangupCall',
                            'params' => [$uuidB],
                        ],
                    ],
                ],
            ],
        ]);

    $hangupResponse->assertOk();
    $hangupContent = (string) $hangupResponse->getContent();
    expect($hangupContent)->toContain('The channel is no longer active.');

    // Repeat for transferCall on Tenant B UUID
    $transferResponse = $this->actingAs($userA, 'web')
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(route('panel.livewire.update'), [
            'components' => [
                [
                    'snapshot' => $tamperedSnapshotString,
                    'updates' => [],
                    'calls' => [
                        [
                            'path' => '',
                            'method' => 'transferCall',
                            'params' => [$uuidB, '2000'],
                        ],
                    ],
                ],
            ],
        ]);

    $transferResponse->assertOk();
    $transferContent = (string) $transferResponse->getContent();
    expect($transferContent)->toContain('The channel is no longer active.');
});

it('rejects client mutations to calls property because it is locked', function (): void {
    $tenantA = Tenant::factory()->create();
    $userA = grantTenantUserPermissions($tenantA, ['active-calls.view']);

    $uuidB = '22222222-2222-2222-2222-222222222222';

    $fs = \Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->andReturn(true);
    $fs->shouldReceive('api')->with('show channels as json')->andReturn('{"rows":[]}');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    $renderResponse = $this->actingAs($userA, 'web')->get(route('panel.active-calls.index'));
    $renderResponse->assertOk();

    [$snapshotJson] = extractComponentSnapshot((string) $renderResponse->getContent(), 'active-calls');

    $response = $this->actingAs($userA, 'web')
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(route('panel.livewire.update'), [
            'components' => [
                [
                    'snapshot' => $snapshotJson,
                    'updates' => [
                        'calls' => [
                            [
                                'uuid' => $uuidB,
                                'caller_id' => '2001',
                                'caller_id_name' => 'Bob',
                                'destination' => '3001',
                                'duration' => 15,
                                'state' => 'CS_EXECUTE',
                                'context' => 'tenant_2_internal',
                            ],
                        ],
                    ],
                    'calls' => [],
                ],
            ],
        ]);

    $response->assertStatus(500);
});
