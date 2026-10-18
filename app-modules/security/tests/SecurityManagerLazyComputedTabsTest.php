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
use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Services\SecurityConfigGenerator;

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

    $generator = Mockery::mock(SecurityConfigGenerator::class)->makePartial();
    $generator->shouldReceive('writePending')->andReturn(sys_get_temp_dir().'/tallpbx-test-firewall.nft.pending');
    $generator->shouldReceive('validateSyntax')->andReturn(true);
    $this->app->instance(SecurityConfigGenerator::class, $generator);
});

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

    // Re-render component on firewall-rules tab
    $component->call('$refresh');

    $queries = collect(DB::getQueryLog())->pluck('query')->all();
    $queriedTables = collect($queries)->map(fn ($sql) => strtolower($sql));

    // Must NOT query inactive tab record collections (whitelist records, blacklist records, scanner incidents)
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

    // Must NOT query inactive tab tables
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

    // Must NOT query inactive tab tables
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

    // Must NOT query inactive tab tables
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

    // Must NOT query inactive tab tables
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

    expect($viewContent)->not->toContain('SecurityGeoRule::instance()')
        ->and($viewContent)->toContain('$this->geoRule');
});
