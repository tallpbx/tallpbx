<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use App\Models\Group;
use Database\Seeders\AdminSeeder;
use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Livewire\SecurityManager;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\Firewall\SecurityConfigGenerator;

/**
 * Feature tests for the evaluation-ordered tab restructure.
 *
 * The Security Center renders four tabs whose order mirrors the kernel
 * evaluation order, binds the active tab to ?tab= deep links, keeps the
 * switch controls and banners pinned above the strip, and renders the
 * movable pre-filter rows alongside the pinned loopback row.
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
        ->assertSee(__('admin.security_firewall_rules_tooltip'))
        ->assertSee(__('admin.security_firewall_rules_cli_hint'))
        ->assertSee('tpbx security:status');
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

it('hides tabs and greys out the dashboard overview when firewall is disabled', function (): void {
    SecuritySetting::set('firewall_enabled', false);

    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    // The whole-firewall-off banner is not shown
    $component->assertDontSee(__('admin.security_firewall_off_banner_title'))
        // Overview dashboard is greyed out
        ->assertSee('opacity-40 pointer-events-none select-none', false)
        // Tab navigation and tablist are completely hidden
        ->assertDontSee('role="tablist"', false)
        ->assertDontSee(__('admin.security_tab_firewall_rules'))
        ->assertDontSee(__('admin.security_tab_block_allow'))
        ->assertDontSee(__('admin.security_tab_external_blocklists'));

    // Enabling via the switch restores tabs and dashboard
    $component->call('setFirewallEnabled', true)
        ->assertSet('firewallEnabled', true)
        ->assertSee('role="tablist"', false)
        ->assertSee(__('admin.security_tab_firewall_rules'))
        ->assertSee(__('admin.security_tab_block_allow'));

    expect(SecuritySetting::getBoolean('firewall_enabled', true))->toBeTrue();
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

it('toggles attack protection, whitelist, and blacklist with accurate counts and prefilter reflection', function (): void {
    // Seed some test data
    SecurityIpList::create(['type' => 'whitelist', 'ip_address' => '198.51.100.10']);
    SecurityIpList::create(['type' => 'blacklist', 'ip_address' => '198.51.100.20']);
    SecurityBan::create([
        'ip_address' => '198.51.100.30',
        'vector' => 'sip_auth',
        'reason' => 'Failed SIP registration',
        'is_active' => true,
        'banned_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    // Initial state: all enabled
    $component->assertSet('whitelistEnabled', true)
        ->assertSet('blacklistEnabled', true)
        ->assertSet('attackProtectionEnabled', true);

    $rows = $component->instance()->preFilterRows();
    $whitelistRow = collect($rows)->firstWhere('key', 'whitelist');
    $blacklistRow = collect($rows)->firstWhere('key', 'blacklist');
    $bannedRow = collect($rows)->firstWhere('key', 'banned');

    expect($whitelistRow['enabled'])->toBeTrue()
        ->and($whitelistRow['count'])->toBeGreaterThan(0)
        ->and($blacklistRow['enabled'])->toBeTrue()
        ->and($blacklistRow['count'])->toBe(1)
        ->and($bannedRow['enabled'])->toBeTrue()
        ->and($bannedRow['count'])->toBe(1);

    // Test disabling blacklist
    $component->call('setBlacklistEnabled', false)
        ->assertSet('blacklistEnabled', false);

    $rows = $component->instance()->preFilterRows();
    $blacklistRow = collect($rows)->firstWhere('key', 'blacklist');
    expect($blacklistRow['enabled'])->toBeFalse()
        ->and($blacklistRow['count'])->toBe(0)
        ->and($blacklistRow['downloaded_count'])->toBe(1);

    // Test disabling attack protection
    $component->call('setAttackProtectionEnabled', false)
        ->assertSet('attackProtectionEnabled', false);

    $rows = $component->instance()->preFilterRows();
    $bannedRow = collect($rows)->firstWhere('key', 'banned');
    expect($bannedRow['enabled'])->toBeFalse()
        ->and($bannedRow['count'])->toBe(0)
        ->and($bannedRow['downloaded_count'])->toBe(1);

    // Test whitelist lockout check: with default policy drop, disabling is refused if admin IP not safe
    SecuritySetting::set('firewall_default_policy', 'drop');
    $component->set('adminIp', '203.0.113.99');
    $component->call('setWhitelistEnabled', false);
    expect($component->get('whitelistEnabled'))->toBeTrue();

    // With loopback admin IP, disabling whitelist is safe and succeeds
    $component->set('adminIp', '127.0.0.1');
    $component->call('setWhitelistEnabled', false)
        ->assertSet('whitelistEnabled', false);

    $rows = $component->instance()->preFilterRows();
    $whitelistRow = collect($rows)->firstWhere('key', 'whitelist');
    expect($whitelistRow['enabled'])->toBeFalse()
        ->and($whitelistRow['count'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Lazy Computed Tab Properties & Query Isolation Tests
|--------------------------------------------------------------------------
*/

it('exposes all required cockpit computed properties with expected types', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    expect($component->instance()->rules())->toBeInstanceOf(Collection::class)
        ->and($component->instance()->serviceRules())->toBeInstanceOf(Collection::class)
        ->and($component->instance()->whitelist())->toBeInstanceOf(Collection::class)
        ->and($component->instance()->blacklist())->toBeInstanceOf(Collection::class)
        ->and($component->instance()->bans())->toBeInstanceOf(Collection::class)
        ->and($component->instance()->scannerIncidents())->toBeInstanceOf(Collection::class)
        ->and($component->instance()->threatFeeds())->toBeInstanceOf(Collection::class)
        ->and($component->instance()->geoRule())->toBeInstanceOf(SecurityGeoRule::class);
});

it('does not query inactive tab records when viewing firewall-rules tab', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'firewall-rules');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $component->call('$refresh');

    $queries = collect(DB::getQueryLog())->pluck('query')->all();
    $queriedTables = collect($queries)->map(fn ($sql) => strtolower($sql));

    expect($queriedTables->contains(fn ($q) => str_contains($q, 'from "security_ip_lists" where "type" = ? order by')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'sip_scanner')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => $q === 'select * from "security_threat_feeds"'))->toBeFalse();
});

it('does not query inactive tab records when viewing geo-blocking tab', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'geo-blocking');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $component->call('$refresh');

    $queries = collect(DB::getQueryLog())->pluck('query')->all();
    $queriedTables = collect($queries)->map(fn ($sql) => strtolower($sql));

    expect($queriedTables->contains(fn ($q) => str_contains($q, 'security_rules')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_services')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_ip_lists')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_threat_feeds')))->toBeFalse();
});

it('does not query inactive tab records when viewing block-allow tab', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'block-allow');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $component->call('$refresh');

    $queries = collect(DB::getQueryLog())->pluck('query')->all();
    $queriedTables = collect($queries)->map(fn ($sql) => strtolower($sql));

    expect($queriedTables->contains(fn ($q) => str_contains($q, 'security_rules')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_services')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_threat_feeds')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_geo_rules')))->toBeFalse();
});

it('does not query inactive tab records when viewing attackers tab', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'attackers');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $component->call('$refresh');

    $queries = collect(DB::getQueryLog())->pluck('query')->all();
    $queriedTables = collect($queries)->map(fn ($sql) => strtolower($sql));

    expect($queriedTables->contains(fn ($q) => str_contains($q, 'security_rules')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_services')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_threat_feeds')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_geo_rules')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_ip_lists')))->toBeFalse();
});

it('does not query inactive tab records when viewing external-blocklists tab', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'external-blocklists');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $component->call('$refresh');

    $queries = collect(DB::getQueryLog())->pluck('query')->all();
    $queriedTables = collect($queries)->map(fn ($sql) => strtolower($sql));

    expect($queriedTables->contains(fn ($q) => str_contains($q, 'security_rules')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_services')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_geo_rules')))->toBeFalse()
        ->and($queriedTables->contains(fn ($q) => str_contains($q, 'security_ip_lists')))->toBeFalse();
});

it('does not execute direct SecurityGeoRule instance query inside geo-blocking view template', function (): void {
    $viewContent = file_get_contents(base_path('app-modules/security/resources/views/tabs/geo-blocking.blade.php'));

    expect($viewContent)->not->toContain('SecurityGeoRule::instance()')
        ->and($viewContent)->toContain('$this->geoRule');
});

it('does not execute direct SecurityGeoRule instance query inside firewall-rules view template', function (): void {
    $viewContent = file_get_contents(base_path('app-modules/security/resources/views/tabs/firewall-rules.blade.php'));
    if (file_exists(base_path('app-modules/security/resources/views/tabs/partials/firewall-prefilters.blade.php'))) {
        $viewContent .= file_get_contents(base_path('app-modules/security/resources/views/tabs/partials/firewall-prefilters.blade.php'));
    }

    expect($viewContent)->not->toContain('SecurityGeoRule::instance()')
        ->and($viewContent)->toContain('$this->geoRule');
});
