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
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\SecurityConfigGenerator;

/**
 * Feature tests for the pre-filter reordering controls in the Security Manager.
 *
 * Verifies the chevron move actions persist a validated order and re-apply the
 * ruleset, the loopback stage and the whitelist-drop constraint are enforced
 * with plain-language refusals, and the reset action restores the default.
 */
beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);

    $this->seed(SecurityServiceSeeder::class);

    $executor = Mockery::mock(SecurityExecutorInterface::class);
    $executor->shouldReceive('ban')->andReturn(true);
    $executor->shouldReceive('unban')->andReturn(true);
    $executor->shouldReceive('apply')->andReturn(true);
    $executor->shouldReceive('status')->andReturn('');
    $executor->shouldReceive('flushConntrack')->andReturn(true);
    $this->app->instance(SecurityExecutorInterface::class, $executor);

    // The move actions validate through the real generator (the order
    // validator must run), so the mock is partial: only the file-writing
    // steps are stubbed to keep the host untouched.
    $generator = Mockery::mock(SecurityConfigGenerator::class)->makePartial();
    $generator->shouldReceive('writePending')->andReturn(sys_get_temp_dir().'/tallpbx-test-firewall.nft.pending');
    $generator->shouldReceive('validateSyntax')->andReturn(true);
    $this->app->instance(SecurityConfigGenerator::class, $generator);
});

it('moves a pre-filter stage down and back up, persisting the order', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    // Default order places invalid directly above fast_path, so moving the
    // invalid stage down swaps exactly those two entries.
    $component->call('movePreFilterDown', 'invalid');

    expect(json_decode((string) SecuritySetting::get('pre_filter_order'), true))
        ->toBe(['loopback', 'whitelist', 'fast_path', 'invalid', 'blacklist', 'banned', 'threat_feeds']);

    $this->assertDatabaseHas('security_audit_logs', ['action' => 'pre_filter_reordered']);

    $component->call('movePreFilterUp', 'invalid');

    expect(json_decode((string) SecuritySetting::get('pre_filter_order'), true))
        ->toBe(SecurityConfigGenerator::DEFAULT_PRE_FILTER_ORDER);
});

it('refuses to move the pinned loopback stage', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    $component->call('movePreFilterDown', 'loopback');

    expect(SecuritySetting::get('pre_filter_order'))->toBeNull()
        ->and($component->get('operationalMessageType'))->toBe('error');
});

it('refuses a move that would put a drop stage above the whitelist', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    // Default order places invalid directly below whitelist, so moving the
    // whitelist down would evaluate the invalid-packet drop first — refused.
    $component->call('movePreFilterDown', 'whitelist');

    expect(SecuritySetting::get('pre_filter_order'))->toBeNull()
        ->and((string) $component->get('operationalMessage'))->toContain('whitelist');
});

it('rejects an unknown stage name', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    $component->call('movePreFilterUp', 'bogus_stage');

    expect(SecuritySetting::get('pre_filter_order'))->toBeNull()
        ->and($component->get('operationalMessageType'))->toBe('error');
});

it('resets the stored order to the recommended default', function (): void {
    SecuritySetting::set('pre_filter_order', json_encode([
        'loopback', 'whitelist', 'fast_path', 'invalid', 'banned', 'blacklist', 'threat_feeds',
    ]));

    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);
    $component->call('resetPreFilterOrder');

    expect(json_decode((string) SecuritySetting::get('pre_filter_order'), true))
        ->toBe(SecurityConfigGenerator::DEFAULT_PRE_FILTER_ORDER);

    $this->assertDatabaseHas('security_audit_logs', ['action' => 'pre_filter_order_reset']);
});
