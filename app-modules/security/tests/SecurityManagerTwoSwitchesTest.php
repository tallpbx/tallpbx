<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use App\Models\Group;
use Database\Seeders\AdminSeeder;
use Database\Seeders\SecurityServiceSeeder;
use Livewire\Livewire;
use Mockery;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Livewire\SecurityManager;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\SecurityConfigGenerator;

/**
 * Feature tests for the firewall switch controls in the Security Manager.
 *
 * Verifies the pre-filter on/off switch, the global observe-mode switch, the
 * lockout-guard refusal that protects the administrator's own connection, and
 * the audit trail written for every switch change.
 */
beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);

    $this->seed(SecurityServiceSeeder::class);

    // Keep privileged host operations out of the test suite. A dedicated
    // mock instance is kept on the test case so refusal assertions can verify
    // the kernel-apply step was never reached.
    $executor = Mockery::mock(SecurityExecutorInterface::class);
    $executor->shouldReceive('ban')->andReturn(true);
    $executor->shouldReceive('unban')->andReturn(true);
    $executor->shouldReceive('apply')->andReturn(true);
    $executor->shouldReceive('status')->andReturn('');
    $executor->shouldReceive('flushConntrack')->andReturn(true);
    $this->app->instance(SecurityExecutorInterface::class, $executor);
    $this->executor = $executor;

    // Partial mock: only the file-writing steps are stubbed; the real order
    // validator runs because the pre-filter row descriptors consult it.
    $generator = Mockery::mock(SecurityConfigGenerator::class)->makePartial();
    $generator->shouldReceive('writePending')->andReturn(sys_get_temp_dir().'/tallpbx-test-firewall.nft.pending');
    $generator->shouldReceive('validateSyntax')->andReturn(true);
    $this->app->instance(SecurityConfigGenerator::class, $generator);
});

it('disables the pre-filter when the administrator IP stays reachable', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.10',
        'description' => 'Admin uplink',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setPrefilterEnabled', false)
        ->assertSet('prefilterEnabled', false);

    expect(SecuritySetting::getBoolean('prefilter_enabled', true))->toBeFalse()
        ->and(session('error'))->toBeNull();
});

it('refuses to disable the pre-filter when the administrator would be dropped', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setPrefilterEnabled', false)
        ->assertSet('prefilterEnabled', true);

    // The change is refused outright: the alert names the blocked address,
    // nothing is persisted, and the kernel apply step is never reached.
    expect($component->get('operationalMessageType'))->toBe('error')
        ->and((string) $component->get('operationalMessage'))->toContain('203.0.113.10')
        ->and(SecuritySetting::getBoolean('prefilter_enabled', true))->toBeTrue();

    $this->executor->shouldNotHaveReceived('apply');
});

it('records an audit entry when the pre-filter is switched off', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.10',
        'description' => 'Admin uplink',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setPrefilterEnabled', false);

    $this->assertDatabaseHas('security_audit_logs', [
        'action' => 'prefilter_disabled',
        'ip_address' => '203.0.113.10',
    ]);
});

it('re-enables the pre-filter without a lockout check', function (): void {
    SecuritySetting::set('prefilter_enabled', false);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setPrefilterEnabled', true)
        ->assertSet('prefilterEnabled', true);

    expect(SecuritySetting::getBoolean('prefilter_enabled', true))->toBeTrue();

    $this->assertDatabaseHas('security_audit_logs', ['action' => 'prefilter_enabled']);
});

it('turns observe mode on and records an audit entry', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('setObserveMode', true)
        ->assertSet('firewallObserveMode', true);

    expect(SecuritySetting::getBoolean('firewall_observe_mode', false))->toBeTrue();

    $this->assertDatabaseHas('security_audit_logs', ['action' => 'observe_mode_enabled']);
});

it('refuses to turn observe mode off when enforcement would drop the administrator', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setObserveMode', false)
        ->assertSet('firewallObserveMode', true);

    // Restoring enforcement is the dangerous direction: the refusal leaves
    // observe mode on, applies nothing, and explains itself.
    expect($component->get('operationalMessageType'))->toBe('error')
        ->and((string) $component->get('operationalMessage'))->toContain('203.0.113.10')
        ->and(SecuritySetting::getBoolean('firewall_observe_mode', false))->toBeTrue();

    $this->executor->shouldNotHaveReceived('apply');
});

it('turns observe mode off when the administrator IP stays reachable', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.10',
        'description' => 'Admin uplink',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setObserveMode', false)
        ->assertSet('firewallObserveMode', false);

    expect(SecuritySetting::getBoolean('firewall_observe_mode', false))->toBeFalse();

    $this->assertDatabaseHas('security_audit_logs', ['action' => 'observe_mode_disabled']);
});
