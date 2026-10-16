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
use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Services\GeoBlockingService;
use Modules\Security\Services\SecurityConfigGenerator;

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
    $this->executor->shouldReceive('apply')->andReturn(true);
    $this->executor->shouldReceive('status')->andReturn('');
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
