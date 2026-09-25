<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\DialplanContext;
use App\Services\DialplanXmlCollector;
use App\Support\RoutingCacheVersion;
use Illuminate\Support\Facades\Cache;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Models\DialplanDetail;
use Modules\Extensions\Models\Extension;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\IvrMenus\Models\IvrMenuOption;
use Modules\OutboundRoutes\Models\OutboundRoute;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Models\RingGroupExtension;
use Modules\TimeConditions\Models\TimeCondition;

beforeEach(function (): void {
    Cache::flush();
});

it('bumps routing cache version when an extension is created, updated, or deleted', function (): void {
    $tenant = Tenant::factory()->create();
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe(0);

    $extension = Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '1001',
    ]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe(1);

    $extension->update(['effective_caller_id_name' => 'Support Desk']);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe(2);

    $extension->delete();
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe(3);
});

it('bumps routing cache version when a ring group or ring group extension is modified', function (): void {
    $tenant = Tenant::factory()->create();
    $extension = Extension::factory()->create(['tenant_id' => $tenant->id]);
    $versionBefore = RoutingCacheVersion::get((int) $tenant->id);

    $ringGroup = RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 1);

    $rgExt = RingGroupExtension::factory()->create([
        'ring_group_id' => $ringGroup->id,
        'extension_uuid' => $extension->id,
        'position' => 1,
    ]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 2);

    $rgExt->update(['position' => 2]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 3);

    $rgExt->delete();
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 4);

    $ringGroup->delete();
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 5);
});

it('bumps routing cache version when an inbound or outbound route is modified', function (): void {
    $tenant = Tenant::factory()->create();
    $versionBefore = RoutingCacheVersion::get((int) $tenant->id);

    $inbound = InboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 1);

    $inbound->update(['destination_data' => '2000']);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 2);

    $outbound = OutboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 3);

    $outbound->delete();
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 4);
});

it('bumps routing cache version when an IVR menu or option is modified', function (): void {
    $tenant = Tenant::factory()->create();
    $versionBefore = RoutingCacheVersion::get((int) $tenant->id);

    $ivr = IvrMenu::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 1);

    $option = IvrMenuOption::factory()->create([
        'ivr_menu_id' => $ivr->id,
        'digit' => '1',
    ]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 2);

    $option->delete();
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 3);

    $ivr->delete();
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 4);
});

it('bumps routing cache version when dialplans or dialplan details are modified', function (): void {
    $tenant = Tenant::factory()->create();
    $versionBefore = RoutingCacheVersion::get((int) $tenant->id);

    $dialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 1);

    $detail = DialplanDetail::factory()->create([
        'dialplan_id' => $dialplan->id,
        'tag' => 'action',
        'action' => 'playback',
    ]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 2);

    $detail->delete();
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 3);

    $dialplan->delete();
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 4);
});

it('bumps both tenants when a model is reassigned to another tenant', function (): void {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    $extension = Extension::factory()->create([
        'tenant_id' => $tenant1->id,
        'extension_number' => '1001',
    ]);

    $v1 = RoutingCacheVersion::get((int) $tenant1->id);
    $v2 = RoutingCacheVersion::get((int) $tenant2->id);

    $extension->update([
        'tenant_id' => $tenant2->id,
    ]);

    expect(RoutingCacheVersion::get((int) $tenant1->id))->toBe($v1 + 1)
        ->and(RoutingCacheVersion::get((int) $tenant2->id))->toBe($v2 + 1);
});

it('immediately invalidates cached dialplan XML when an extension is updated', function (): void {
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.dialplan_cache_ttl' => 60,
    ]);
    Cache::store('array')->flush();

    $tenant = Tenant::factory()->create();
    $context = app(DialplanContext::class)->public((string) $tenant->id);

    $collector = Mockery::mock(DialplanXmlCollector::class);
    $collector->shouldReceive('collect')
        ->twice()
        ->with((int) $tenant->id, $context, '2000')
        ->andReturn('<extension name="v1"/>', '<extension name="v2"/>');
    app()->instance(DialplanXmlCollector::class, $collector);

    // Initial query hits the collector and caches the result
    $query = http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '2000',
    ]);
    $this->get('/api/v1/xml-handler?'.$query)->assertOk()->assertSee('v1');

    // Repeated query hits the cache (collector is NOT called again)
    $this->get('/api/v1/xml-handler?'.$query)->assertOk()->assertSee('v1');

    // Create an extension for this tenant: bumps routing cache version and invalidates the cached XML
    Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '2000',
    ]);

    // Next query must miss the cache and hit the collector again immediately with fresh XML
    $this->get('/api/v1/xml-handler?'.$query)->assertOk()->assertSee('v2');
});
