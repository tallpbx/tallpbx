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
 * Feature tests for the evaluation-ordered tab restructure.
 *
 * The Security Center renders four tabs whose order mirrors the kernel
 * evaluation order, binds the active tab to ?tab= deep links, keeps the
 * switch controls and banners pinned above the strip, and renders the
 * reorderable pre-filter rows.
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

    // The pre-filter row descriptors validate through the real generator.
    $generator = Mockery::mock(SecurityConfigGenerator::class)->makePartial();
    $generator->shouldReceive('writePending')->andReturn(sys_get_temp_dir().'/tallpbx-test-firewall.nft.pending');
    $generator->shouldReceive('validateSyntax')->andReturn(true);
    $this->app->instance(SecurityConfigGenerator::class, $generator);
});

it('defaults to the Firewall Rules tab and renders every tab control', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('activeTab', 'firewall-rules')
        ->assertSee(__('admin.security_tab_firewall_rules'))
        ->assertSee(__('admin.security_tab_block_allow'))
        ->assertSee(__('admin.security_tab_attackers'))
        ->assertSee(__('admin.security_tab_threat_feeds'))
        // The page-global switch controls stay visible above the strip.
        ->assertSee(__('admin.security_toggle_firewall'))
        ->assertSee(__('admin.security_toggle_observe'))
        ->assertSee(__('admin.security_firewall_rules_desc'))
        ->assertSee(__('admin.security_firewall_rules_cli_hint'))
        ->assertSee('php artisan security:status');
});

it('shows only the selected tab panel', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    // Firewall Rules is the default open tab and shows the full pipeline summary.
    $component->assertSee('iif "lo"')
        ->assertSee(__('admin.security_core_services_title'));

    // Allow & Block Lists shows the allow/block workbenches, not the pipeline.
    $component->set('activeTab', 'block-allow')
        ->assertSee(__('admin.security_add_to_whitelist'))
        ->assertDontSee('iif "lo"');

    // Attackers shows the bans workbench and hides the pipeline table.
    $component->set('activeTab', 'attackers')
        ->assertSee(__('admin.security_block_manually'))
        ->assertDontSee('iif "lo"');

    // Threat Feeds shows the feed controls panel.
    $component->set('activeTab', 'threat-feeds')
        ->assertSee(__('admin.security_threat_feeds_title'))
        ->assertDontSee('iif "lo"');
});

it('binds the active tab to the ?tab deep link', function (): void {
    Livewire::withQueryParams(['tab' => 'threat-feeds'])
        ->actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('activeTab', 'threat-feeds');
});

it('shows the whole-firewall-off and observe banners with one-click recovery', function (): void {
    SecuritySetting::set('firewall_enabled', false);
    SecuritySetting::set('firewall_observe_mode', true);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSee(__('admin.security_firewall_off_banner_title'))
        ->assertSee(__('admin.security_observe_banner_title'))
        ->call('setFirewallEnabled', true)
        ->call('setObserveMode', false)
        ->assertSet('firewallEnabled', true)
        ->assertSet('firewallObserveMode', false);

    expect(SecuritySetting::getBoolean('firewall_enabled', true))->toBeTrue()
        ->and(SecuritySetting::getBoolean('firewall_observe_mode', false))->toBeFalse();
});

it('renders reorder controls on movable pre-filter rows and a lock on loopback', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'firewall-rules')
        ->assertSee("movePreFilterUp('whitelist')", false)
        ->assertSee("movePreFilterDown('whitelist')", false)
        ->assertSee(__('admin.security_prefilter_locked_tooltip'))
        ->assertSee(__('admin.security_prefilter_reset'))
        ->assertDontSee("movePreFilterUp('loopback')", false);
});

it('disables the reorder controls while the pre-filter is off', function (): void {
    SecuritySetting::set('prefilter_enabled', false);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'firewall-rules')
        ->assertSet('prefilterEnabled', false)
        ->assertSee('disabled', false);
});

it('shows defensive pre-filters disabled banner across tabs with one-click recovery', function (): void {
    SecuritySetting::set('firewall_enabled', true);
    SecuritySetting::set('prefilter_enabled', false);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSee(__('admin.security_prefilter_disabled_banner_title'))
        ->assertSee(__('admin.security_prefilter_disabled_banner_body'))
        ->assertSee(__('admin.security_prefilter_enable_action'))
        ->call('setPrefilterEnabled', true)
        ->assertSet('prefilterEnabled', true)
        ->assertDontSee(__('admin.security_prefilter_disabled_banner_title'));
});

it('renders bypassed badges on affected tabs and in-tab notices when pre-filter is disabled', function (): void {
    SecuritySetting::set('firewall_enabled', true);
    SecuritySetting::set('prefilter_enabled', false);

    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    // Bypassed badge appears on the tabs
    $component->assertSee(__('admin.security_bypassed_badge'));

    // Bypassed notice in Allow & Block Lists
    $component->set('activeTab', 'block-allow')
        ->assertSee(__('admin.security_prefilter_bypassed_block_allow_notice'));

    // Bypassed notice in Attackers
    $component->set('activeTab', 'attackers')
        ->assertSee(__('admin.security_prefilter_bypassed_attackers_notice'));

    // Bypassed notice in Threat Feeds
    $component->set('activeTab', 'threat-feeds')
        ->assertSee(__('admin.security_prefilter_bypassed_threat_feeds_notice'));
});

it('prompts confirmation modal before disabling pre-filters and executes safely', function (): void {
    SecuritySetting::set('firewall_default_policy', 'accept');
    $this->admin->update(['ip_address' => '203.0.113.10']);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->assertSet('showDisablePrefilterModal', false);

    $component->call('confirmDisablePrefilter')
        ->assertSet('showDisablePrefilterModal', true)
        ->assertSee(__('admin.security_disable_prefilter_modal_title'))
        ->assertSee(__('admin.security_disable_prefilter_btn'));

    $component->call('closeDisablePrefilterModal')
        ->assertSet('showDisablePrefilterModal', false);

    $component->call('confirmDisablePrefilter')
        ->assertSet('showDisablePrefilterModal', true)
        ->call('executeDisablePrefilter')
        ->assertSet('showDisablePrefilterModal', false)
        ->assertSet('prefilterEnabled', false);
});
