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
 * lockout-guard refusals that protect both the administrator's own connection
 * and the server's loopback database/cache connections, the default-policy
 * form guard, and the audit trail written for every switch change.
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

it('disables the pre-filter when the administrator stays reachable and the policy allows', function (): void {
    // Removing the pre-filter is only allowed when the remaining ruleset is
    // fully open: with the stored policy blocking, the loopback connections
    // of the server's own services would fall through to the default drop.
    SecuritySetting::set('firewall_default_policy', 'accept');

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

    $this->executor->shouldHaveReceived('apply')->once();
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

it('refuses to disable the pre-filter when local services would lose their loopback connection', function (): void {
    // The administrator is whitelisted, but the seeded default policy still
    // blocks: removing the pre-filter would also remove the loopback accept
    // that the panel's own database and cache connections rely on, so the
    // change is refused even though this operator's own address is safe.
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.10',
        'description' => 'Admin uplink',
    ]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setPrefilterEnabled', false)
        ->assertSet('prefilterEnabled', true);

    expect($component->get('operationalMessageType'))->toBe('error')
        ->and((string) $component->get('operationalMessage'))->toContain(__('admin.security_prefilter_local_services_refused'))
        ->and(SecuritySetting::getBoolean('prefilter_enabled', true))->toBeTrue();

    $this->executor->shouldNotHaveReceived('apply');
});

it('records an audit entry when the pre-filter is switched off', function (): void {
    // Removing the pre-filter requires an allowing default policy: the
    // local-services guard refuses the blocking combination.
    SecuritySetting::set('firewall_default_policy', 'accept');

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

it('refuses to leave observe mode when the pre-filter is off and the policy would drop local services', function (): void {
    // The operator's request itself comes from the loopback address (safe),
    // but leaving observe mode with the pre-filter off would make the stored
    // blocking policy take effect and cut the panel off from its own services.
    SecuritySetting::set('firewall_observe_mode', true);
    SecuritySetting::set('prefilter_enabled', false);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('setObserveMode', false)
        ->assertSet('firewallObserveMode', true);

    expect($component->get('operationalMessageType'))->toBe('error')
        ->and((string) $component->get('operationalMessage'))->toContain(__('admin.security_observe_local_services_refused'))
        ->and(SecuritySetting::getBoolean('firewall_observe_mode', false))->toBeTrue();

    $this->executor->shouldNotHaveReceived('apply');
});

it('refuses to switch the default policy to blocking while the pre-filter is off', function (): void {
    SecuritySetting::set('firewall_default_policy', 'accept');
    SecuritySetting::set('prefilter_enabled', false);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openDefaultPolicyForm')
        ->set('firewallDefaultPolicy', 'drop')
        ->call('saveDefaultPolicy');

    // Nothing is persisted and the kernel apply step is never reached.
    expect($component->get('operationalMessageType'))->toBe('error')
        ->and((string) $component->get('operationalMessage'))->toContain(__('admin.security_default_policy_local_services_refused'))
        ->and(SecuritySetting::get('firewall_default_policy'))->toBe('accept');

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

it('ships the local-services refusal translations in English, Spanish, and French', function (): void {
    $required = [
        'security_prefilter_local_services_refused',
        'security_observe_local_services_refused',
        'security_default_policy_local_services_refused',
    ];

    foreach (['en', 'es', 'fr'] as $locale) {
        $lines = require lang_path($locale.'/admin.php');
        $missing = array_values(array_diff($required, array_keys($lines)));

        expect($missing)->toBe([], 'Missing keys in '.$locale.': '.implode(', ', $missing));
    }
});

it('rolls back the master firewall switch when the guard refuses the change', function (): void {
    // Enabling passes the same lockout guard as every apply; when it is
    // refused the stored switch must snap back to disabled — otherwise the
    // panel would claim the firewall is on while the kernel still runs the
    // fully open ruleset, and a later CLI apply would enforce a drop policy.
    SecuritySetting::set('firewall_enabled', false);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setFirewallEnabled', true)
        ->assertSet('firewallEnabled', false);

    expect($component->get('operationalMessageType'))->toBe('error')
        ->and(SecuritySetting::getBoolean('firewall_enabled', true))->toBeFalse();

    $this->executor->shouldNotHaveReceived('apply');
});
