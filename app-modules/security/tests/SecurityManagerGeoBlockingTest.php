<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use App\Models\Group;
use Database\Seeders\AdminSeeder;
use Database\Seeders\SecurityServiceSeeder;
use Livewire\Livewire;
use Mockery;
use Modules\Security\Contracts\GeoBlockingProviderInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Livewire\SecurityManager;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\Firewall\SecurityConfigGenerator;
use Modules\Security\Services\Geo\GeoBlockingService;

beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);

    $this->seed(SecurityServiceSeeder::class);

    $this->tempDir = sys_get_temp_dir().'/tallpbx_geo_ui_test_'.uniqid();
    mkdir($this->tempDir, 0700, true);
    $this->cacheDir = sys_get_temp_dir().'/tallpbx_geo_ui_cache_'.uniqid();
    mkdir($this->cacheDir, 0700, true);

    $this->executor = Mockery::mock(SecurityExecutorInterface::class);
    $this->executor->shouldReceive('ban')->andReturn(true);
    $this->executor->shouldReceive('unban')->andReturn(true);
    // byDefault keeps this permissive default demoted so individual tests
    // can declare their own scoped apply expectations (counts/sequences).
    $this->executor->shouldReceive('apply')->byDefault()->andReturn(true);
    $this->executor->shouldReceive('status')->byDefault()->andReturn('');
    $this->executor->shouldReceive('flushConntrack')->andReturn(true);
    $this->executor->shouldReceive('updateExternalBlocklist')->byDefault()->andReturn(true);
    $this->app->instance(SecurityExecutorInterface::class, $this->executor);

    $this->geoProvider = Mockery::mock(GeoBlockingProviderInterface::class);
    $this->geoProvider->shouldReceive('fetchCountryRanges')
        ->byDefault()
        ->andReturn([
            'v4' => ['1.2.3.0/24'],
            'v6' => ['2600::/12'],
        ]);
    $this->app->instance(GeoBlockingProviderInterface::class, $this->geoProvider);

    $generator = Mockery::mock(SecurityConfigGenerator::class)->makePartial();
    $generator->shouldReceive('writePending')->andReturn(sys_get_temp_dir().'/tallpbx-test-geo-firewall.nft.pending');
    $generator->shouldReceive('validateSyntax')->andReturn(true);
    $this->app->instance(SecurityConfigGenerator::class, $generator);

    $geoService = new GeoBlockingService($this->geoProvider, $this->executor, $this->tempDir, $this->cacheDir);
    $this->app->instance(GeoBlockingService::class, $geoService);
});

afterEach(function (): void {
    foreach ([$this->tempDir, $this->cacheDir] as $dir) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

it('renders the Geo-Blocking tab button and panel', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->assertOk()
        ->assertSee(__('admin.security_tab_geo_blocking'))
        ->assertSee(__('admin.security_geo_blocking_title'));
});

it('toggles countries and saves settings', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->call('toggleGeoCountry', 'US')
        ->assertSet('geoCountries', ['US']);

    $rule = SecurityGeoRule::instance();
    expect($rule->countries)->toBe(['US']);
});

it('prevents self-lockout when enabling geofence without whitelist coverage', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'whitelist')
        ->set('geoCountries', ['US'])
        ->call('setGeoEnabled', true);

    $rule = SecurityGeoRule::instance();
    expect($rule->enabled)->toBeFalse();

    // Now whitelist the admin IP
    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'whitelist')
        ->set('geoCountries', ['US'])
        ->call('setGeoEnabled', true);

    expect(SecurityGeoRule::instance()->enabled)->toBeTrue();
});

it('lets an admin with only the granular geo permission toggle geo blocking', function (): void {
    $geoAdmin = grantAdminPermissions(
        Admin::factory()->create(['enabled' => true]),
        ['security.view', 'security.geo-blocking.manage']
    );

    $rule = SecurityGeoRule::instance();
    $rule->mode = 'blacklist';
    $rule->countries = ['US'];
    $rule->save();

    Livewire::actingAs($geoAdmin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->call('setGeoEnabled', true)
        ->assertOk();

    expect(SecurityGeoRule::instance()->enabled)->toBeTrue();
});

it('rejects geo mutations from admins without geo permissions before any state change', function (): void {
    $viewer = grantAdminPermissions(
        Admin::factory()->create(['enabled' => true]),
        ['security.view']
    );

    $rule = SecurityGeoRule::instance();
    $rule->mode = 'blacklist';
    $rule->countries = ['US'];
    $rule->enabled = false;
    $rule->save();

    Livewire::actingAs($viewer, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->call('setGeoEnabled', true)
        ->assertForbidden();

    expect(SecurityGeoRule::instance()->enabled)->toBeFalse();
});

it('populates geo allow sets before applying the firewall ruleset when enabling whitelist mode', function (): void {
    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    // Record the actual call sequence and capture the compiled geo file at
    // the moment the sets are promoted; the ruleset apply must come after.
    $callOrder = [];
    $geoFileAtPromotion = null;
    $this->executor->shouldReceive('updateExternalBlocklist')
        ->once()
        ->andReturnUsing(function () use (&$callOrder, &$geoFileAtPromotion): bool {
            $callOrder[] = 'promote_sets';
            $geoFileAtPromotion = file_get_contents($this->tempDir.'/geo_blocking.nft.pending') ?: '';

            return true;
        });
    $this->executor->shouldReceive('apply')
        ->once()
        ->andReturnUsing(function () use (&$callOrder): bool {
            $callOrder[] = 'apply_ruleset';

            return true;
        });

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'whitelist')
        ->set('geoCountries', ['US'])
        ->call('setGeoEnabled', true)
        ->assertOk();

    expect(SecurityGeoRule::instance()->enabled)->toBeTrue()
        ->and($callOrder)->toBe(['promote_sets', 'apply_ruleset'])
        ->and($geoFileAtPromotion)->toContain('add element inet tallpbx_filter geo_allowed_ips');
});

it('refuses to enable whitelist geo blocking when the zone sync fails', function (): void {
    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    // Provider down: no ranges and no cache available.
    $this->geoProvider->shouldReceive('fetchCountryRanges')
        ->byDefault()
        ->andReturn(['v4' => [], 'v6' => []]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'whitelist')
        ->set('geoCountries', ['US'])
        ->call('setGeoEnabled', true)
        ->assertOk();

    expect(SecurityGeoRule::instance()->enabled)->toBeFalse();
});

it('refuses to enable whitelist geo blocking with no countries selected', function (): void {
    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'whitelist')
        ->set('geoCountries', [])
        ->call('setGeoEnabled', true)
        ->assertOk();

    expect(SecurityGeoRule::instance()->enabled)->toBeFalse();
});

it('refuses to enable whitelist geo blocking while pre-filters are disarmed', function (): void {
    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    // Disarmed pre-filters remove the trusted-whitelist accept stage, so
    // the geo drop would precede any accept for this administrator's IP.
    SecuritySetting::updateOrCreate(['key' => 'prefilter_enabled'], ['value' => '0']);

    // The refusal must happen up front: no zone downloads may be attempted
    // for a perimeter that can never be armed safely.
    $this->geoProvider->shouldReceive('fetchCountryRanges')->never();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'whitelist')
        ->set('geoCountries', ['US'])
        ->call('setGeoEnabled', true)
        ->assertOk();

    expect(SecurityGeoRule::instance()->enabled)->toBeFalse();
});

it('refuses switching an enabled rule into whitelist mode while pre-filters are disarmed', function (): void {
    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    SecuritySetting::updateOrCreate(['key' => 'prefilter_enabled'], ['value' => '0']);

    $rule = SecurityGeoRule::instance();
    $rule->mode = 'blacklist';
    $rule->countries = ['US'];
    $rule->enabled = true;
    $rule->save();

    // Same up-front refusal requirement when switching modes on an armed rule.
    $this->geoProvider->shouldReceive('fetchCountryRanges')->never();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'whitelist')
        ->set('geoCountries', ['US'])
        ->call('saveGeoSettings')
        ->assertOk();

    // The mode must stay blacklist instead of arming a whitelist perimeter
    // whose trusted-IP accept stage is currently switched off.
    expect(SecurityGeoRule::instance()->mode)->toBe('blacklist');
});

it('restores kernel geo sets when a settings apply fails on an armed whitelist rule', function (): void {
    // Whitelist the admin IP so the lockout preflight passes and the
    // refusal demonstrably comes from the kernel apply step below.
    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    $rule = SecurityGeoRule::instance();
    $rule->mode = 'whitelist';
    $rule->countries = ['US'];
    $rule->sync_interval = 'weekly';
    $rule->enabled = true;
    $rule->save();

    // Prime the zone cache so both the attempted switch and the rollback
    // re-promotion compile offline.
    file_put_contents($this->cacheDir.'/US.v4.zone', "1.0.0.0/24\n");
    file_put_contents($this->cacheDir.'/US.v6.zone', "2600::/12\n");

    // The kernel apply refuses (e.g. lockout guard): the change must roll
    // back everywhere, including the promoted kernel sets.
    $this->executor->shouldReceive('apply')->once()->andReturn(false);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'blacklist')
        ->set('geoSyncInterval', 'monthly')
        ->set('geoCountries', ['US'])
        ->call('saveGeoSettings')
        ->assertOk();

    $fresh = SecurityGeoRule::instance();
    expect($fresh->mode)->toBe('whitelist')
        ->and($fresh->sync_interval)->toBe('weekly')
        ->and($fresh->countries)->toBe(['US'])
        ->and($fresh->enabled)->toBeTrue();

    // The previously armed allow-set must be re-promoted, not left as the
    // half-applied blacklist compilation.
    $content = file_get_contents($this->tempDir.'/geo_blocking.nft.pending');
    expect($content)->toContain('add element inet tallpbx_filter geo_allowed_ips')
        ->and($content)->not->toContain('add element inet tallpbx_filter geo_blocked_ips {');
});

it('restores an armed perimeter when a re-enable with a new mode cannot sync', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->mode = 'blacklist';
    $rule->countries = ['US'];
    $rule->enabled = true;
    $rule->save();

    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    // Provider down with no cache: the new whitelist selection cannot be
    // downloaded, so enablement must be refused WITHOUT degrading the
    // already-armed blacklist perimeter.
    $this->geoProvider->shouldReceive('fetchCountryRanges')->byDefault()->andReturn(['v4' => [], 'v6' => []]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'whitelist')
        ->set('geoCountries', ['US'])
        ->call('setGeoEnabled', true)
        ->assertOk();

    $fresh = SecurityGeoRule::instance();
    expect($fresh->enabled)->toBeTrue()
        ->and($fresh->mode)->toBe('blacklist')
        ->and($fresh->countries)->toBe(['US']);
});

it('refuses saving an empty country selection on an armed whitelist rule', function (): void {
    // Whitelist the admin IP so the earlier lockout guard does not mask the
    // empty-selection refusal this test pins.
    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    $rule = SecurityGeoRule::instance();
    $rule->mode = 'whitelist';
    $rule->countries = ['US'];
    $rule->enabled = true;
    $rule->save();

    $this->geoProvider->shouldReceive('fetchCountryRanges')->never();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'whitelist')
        ->set('geoCountries', [])
        ->call('saveGeoSettings')
        ->assertOk();

    $fresh = SecurityGeoRule::instance();
    expect($fresh->countries)->toBe(['US'])
        ->and($fresh->mode)->toBe('whitelist');
});

it('refuses clearing countries while a whitelist perimeter is armed', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->mode = 'whitelist';
    $rule->countries = ['US'];
    $rule->enabled = true;
    $rule->save();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->call('clearGeoRules')
        ->assertOk();

    $fresh = SecurityGeoRule::instance();
    expect($fresh->countries)->toBe(['US'])
        ->and($fresh->enabled)->toBeTrue();
});

it('surfaces an error notification and records sync status when saveGeoSettings sync fails in blacklist mode', function (): void {
    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    $rule = SecurityGeoRule::instance();
    $rule->mode = 'blacklist';
    $rule->countries = ['US'];
    $rule->enabled = true;
    $rule->save();

    // Provider returns empty ranges with no cached zones, causing sync to fail
    $this->geoProvider->shouldReceive('fetchCountryRanges')->with('CA')->andReturn([
        'v4' => [],
        'v6' => [],
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'blacklist')
        ->set('geoCountries', ['CA'])
        ->call('saveGeoSettings')
        ->assertOk()
        ->assertSet('operationalMessageType', 'error');

    $audit = SecurityAuditLog::where('action', 'geo_blocking_updated')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->description)->toContain('sync: failed');
});

it('pairs the geo drop counter with its own rule line', function (): void {
    // A realistic nft dump: the geo set DEFINITION lines carry no counter,
    // and an unrelated external-blocklist rule counter precedes the geo
    // rule. The reported counter must belong to the geo rule itself.
    $this->executor->shouldReceive('status')->andReturn(implode("\n", [
        'set geo_blocked_ips {',
        '    type ipv4_addr',
        '    flags interval',
        '}',
        'set geo_allowed_ips {',
        '    type ipv4_addr',
        '    flags interval',
        '}',
        'chain input {',
        '    ip saddr @external_blocklist_ips counter packets 500 bytes 0 drop',
        '    ip saddr @geo_blocked_ips counter packets 42 bytes 0 drop',
        '}',
    ]));

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->call('geoDropCounter')
        ->assertReturned(42);
});

it('rolls back database and kernel sets when enabling geofence fails firewall apply', function (): void {
    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    $rule = SecurityGeoRule::instance();
    $rule->mode = 'whitelist';
    $rule->countries = ['US'];
    $rule->enabled = false;
    $rule->save();

    // Firewall apply refuses (e.g. syntax or lockout guard rejection)
    $this->executor->shouldReceive('apply')->once()->andReturn(false);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'whitelist')
        ->set('geoCountries', ['US'])
        ->call('setGeoEnabled', true)
        ->assertOk()
        ->assertSet('geoEnabled', false);

    $fresh = SecurityGeoRule::instance();
    expect($fresh->enabled)->toBeFalse();

    // Since the rule was previously disabled, rollback invokes clear() which flushes geo sets
    $pending = file_get_contents($this->tempDir.'/geo_blocking.nft.pending');
    expect($pending)->toContain('flush set inet tallpbx_filter geo_allowed_ips')
        ->and($pending)->not->toContain('add element inet tallpbx_filter geo_allowed_ips');
});

it('rolls back database when disabling geofence fails firewall apply', function (): void {
    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    $rule = SecurityGeoRule::instance();
    $rule->mode = 'whitelist';
    $rule->countries = ['US'];
    $rule->enabled = true;
    $rule->save();

    // Firewall apply refuses when attempting to remove the geo perimeter chain
    $this->executor->shouldReceive('apply')->once()->andReturn(false);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->call('setGeoEnabled', false)
        ->assertOk()
        ->assertSet('geoEnabled', true);

    $fresh = SecurityGeoRule::instance();
    expect($fresh->enabled)->toBeTrue();
});

it('re-promotes previous kernel geo sets when re-arming geofence fails firewall apply', function (): void {
    SecurityIpList::create([
        'ip_address' => '198.51.100.50',
        'type' => 'whitelist',
    ]);

    // Previously armed blacklist perimeter: the rollback path must restore
    // these exact kernel sets when the re-arm is refused by the firewall
    // apply, because the aborted sync already overwrote them.
    $rule = SecurityGeoRule::instance();
    $rule->mode = 'blacklist';
    $rule->countries = ['US'];
    $rule->enabled = true;
    $rule->save();

    // Firewall apply refuses after the new mode's sets were already synced
    $this->executor->shouldReceive('apply')->once()->andReturn(false);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->set('adminIp', '198.51.100.50')
        ->set('geoMode', 'whitelist')
        ->set('geoCountries', ['US'])
        ->call('setGeoEnabled', true)
        ->assertOk()
        ->assertSet('geoEnabled', true);

    // Database restored to the previously armed blacklist state
    $fresh = SecurityGeoRule::instance();
    expect($fresh->enabled)->toBeTrue()
        ->and($fresh->mode)->toBe('blacklist');

    // Kernel geo sets re-promoted to match the restored state: the aborted
    // whitelist content must not survive in the pending file.
    $pending = file_get_contents($this->tempDir.'/geo_blocking.nft.pending');
    expect($pending)->toContain('add element inet tallpbx_filter geo_blocked_ips')
        ->and($pending)->not->toContain('add element inet tallpbx_filter geo_allowed_ips');
});

it('opens and cancels clear geo rules confirmation modal', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'geo-blocking'])
        ->assertSet('confirmingClearGeoRules', false)
        ->call('confirmClearGeoRules')
        ->assertSet('confirmingClearGeoRules', true)
        ->assertSee(__('admin.security_geo_clear_rules'))
        ->call('cancelClearGeoRules')
        ->assertSet('confirmingClearGeoRules', false);
});
