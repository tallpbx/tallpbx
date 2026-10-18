<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\AdminSeeder;
use Database\Seeders\SecurityServiceSeeder;
use Livewire\Livewire;
use Mockery;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Livewire\SecurityManager;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\Firewall\FirewallSyncVerifier;
use Modules\Security\Services\Firewall\SecurityConfigGenerator;
use Modules\Security\Support\FirewallSyncStatus;

/**
 * Feature tests for security.edit permission gating in SecurityManager.
 *
 * Verifies that read-only administrators holding only security.view can
 * inspect the Security Center but are refused (403 Forbidden) on all
 * configuration, rule, IP list, and kernel-affecting mutations.
 */
beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->seed(SecurityServiceSeeder::class);

    $executor = Mockery::mock(SecurityExecutorInterface::class);
    $executor->shouldReceive('ban')->andReturn(true);
    $executor->shouldReceive('unban')->andReturn(true);
    $executor->shouldReceive('apply')->andReturn(true);
    $executor->shouldReceive('status')->andReturn('');
    $executor->shouldReceive('flushConntrack')->andReturn(true);
    $this->app->instance(SecurityExecutorInterface::class, $executor);

    $generator = Mockery::mock(SecurityConfigGenerator::class)->makePartial();
    $generator->shouldReceive('writePending')->andReturn(sys_get_temp_dir().'/tallpbx-test-auth-firewall.nft.pending');
    $generator->shouldReceive('validateSyntax')->andReturn(true);
    $this->app->instance(SecurityConfigGenerator::class, $generator);

    $verifier = Mockery::mock(FirewallSyncVerifier::class);
    $verifier->shouldReceive('verify')->andReturn(
        FirewallSyncStatus::unknown(['No verified apply record found.'])
    );
    $this->app->instance(FirewallSyncVerifier::class, $verifier);

    $this->viewOnlyAdmin = grantAdminPermissions(permissions: ['security.view']);
    $this->editAdmin = grantAdminPermissions(permissions: ['security.view', 'security.edit']);
});

it('permits view-only admins to render the security command center', function (): void {
    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->assertOk()
        ->assertSee('Security Center')
        ->assertSee('Firewall Status');
});

it('refuses master firewall switch and apply mutations without security.edit permission', function (): void {
    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('setFirewallEnabled', false)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('applyFirewallChanges')
        ->assertStatus(403);
});

it('refuses allow and block list mutations without security.edit permission', function (): void {
    $ipList = SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '198.51.100.50',
        'description' => 'Test IP',
    ]);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('setWhitelistEnabled', false)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('setBlacklistEnabled', false)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('whitelistCurrentIp')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->set('newBlacklistIp', '198.51.100.99')
        ->call('addBlacklistIp')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->set('newWhitelistIp', '198.51.100.100')
        ->call('addWhitelistIp')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('deleteIp', $ipList->id)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('promoteToWhitelist', '198.51.100.77')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('promoteToBlacklist', '198.51.100.88')
        ->assertStatus(403);
});

it('refuses attack protection and ban mutations without security.edit permission', function (): void {
    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('unban', '198.51.100.1')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->set('manualBanIp', '198.51.100.2')
        ->set('manualBanDuration', 3600)
        ->call('manualBan')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('setSipScannerEnforcement', false)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('setSipScannerBanSeconds', 3600)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->set('newScannerSignature', 'friendly-scanner-agent')
        ->call('addScannerSignature')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('removeScannerSignature', 'friendly-scanner-agent')
        ->assertStatus(403);

    SecurityBan::create([
        'ip_address' => '198.51.100.3',
        'vector' => 'sip_scanner',
        'reason' => 'test scanner',
        'is_active' => false,
    ]);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('promoteScannerIncident', '198.51.100.3')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('setTftpDefense', false)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('saveSettings')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('setAttackProtectionEnabled', false)
        ->assertStatus(403);
});

it('refuses firewall rules, policy, and system service mutations without security.edit permission', function (): void {
    $service = SecurityService::first();
    $rule1 = SecurityRule::create([
        'sequence' => 10,
        'description' => 'Rule 1',
        'source_ip' => 'any',
        'service_id' => $service->id,
        'action' => 'accept',
        'enabled' => true,
    ]);
    $rule2 = SecurityRule::create([
        'sequence' => 20,
        'description' => 'Rule 2',
        'source_ip' => 'any',
        'service_id' => $service->id,
        'action' => 'accept',
        'enabled' => true,
    ]);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->set('firewallDefaultPolicy', 'accept')
        ->call('saveDefaultPolicy')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('moveRuleUp', $rule2->id)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('moveRuleDown', $rule1->id)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('toggleRule', $rule1->id)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('deleteRule', $rule1->id)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('addServiceFromCatalog', $service->id)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->set('editingSystemServiceId', $service->id)
        ->set('systemServicePortRange', '5060')
        ->set('systemServiceProtocol', 'udp')
        ->set('systemServiceSourceIp', 'any')
        ->call('saveSystemService')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('toggleSystemService', $service->id)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('resetSystemServiceToDefault', $service->id)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->set('ruleDescription', 'Test Custom Rule')
        ->set('ruleSourceIp', 'any')
        ->set('ruleCustomPort', '8080')
        ->set('ruleCustomProtocol', 'tcp')
        ->set('ruleAction', 'accept')
        ->set('ruleEnabled', true)
        ->call('saveCustomRule')
        ->assertStatus(403);
});

it('refuses observe mode and pre-filter mutations without security.edit permission', function (): void {
    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('setObserveMode', true)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('setPrefilterEnabled', false)
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('executeDisablePrefilter')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('movePreFilterUp', 'whitelist')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('movePreFilterDown', 'whitelist')
        ->assertStatus(403);

    Livewire::actingAs($this->viewOnlyAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('resetPreFilterOrder')
        ->assertStatus(403);
});

it('permits admins with security.edit permission to perform mutations', function (): void {
    Livewire::actingAs($this->editAdmin, 'admin')
        ->test(SecurityManager::class)
        ->call('setBlacklistEnabled', false)
        ->assertOk();

    expect(SecuritySetting::getBoolean('blacklist_enabled'))->toBeFalse();
});
