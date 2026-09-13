<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\DialplanContext;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Models\DialplanDetail;
use Modules\TenantLimits\Models\TenantLimit;
use Modules\TenantLimits\Services\TenantLimitService;

use function Pest\Laravel\get;

beforeEach(function (): void {
    // Same isolation as the handler tests: auth off, array cache, no TTLs.
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.dialplan_cache_store' => 'array',
        'freeswitch.xml_handler.dialplan_cache_ttl' => 0,
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 0,
        'freeswitch.xml_handler.directory_cache_ttl' => 0,
        'freeswitch.xml_handler.log_timing' => false,
        'freeswitch.xml_handler.hiredis_limit_enabled' => true,
        'freeswitch.xml_handler.hiredis_marker_enabled' => false,
    ]);
    Cache::store('array')->flush();
    app(TenantManager::class)->clear();
});

/**
 * Create a tenant for a tenant limit dialplan scenario.
 */
function tenantLimitDialplanTenant(): Tenant
{
    return Tenant::factory()->create(['enabled' => true]);
}

/**
 * Seed a local_extension dialplan so the hiredis enrichment fires.
 */
function tenantLimitDialplanLocalExtension(Tenant $tenant): Dialplan
{
    $context = app(DialplanContext::class)->internal((string) $tenant->id);
    $dialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'local_extension',
        'context' => $context,
        'order' => 30,
        'enabled' => true,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dialplan->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^([1-9][0-9]{2,5})$',
        'action' => 'bridge',
        'data' => '${sofia_contact($1@${domain_name})}',
        'order' => 30,
    ]);

    return $dialplan;
}

/**
 * Request the dialplan section of the real XML handler endpoint.
 */
function tenantLimitDialplan(string $context, string $destination): TestResponse
{
    return get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => $destination,
        'Caller-Caller-ID-Number' => '2001',
    ]));
}

it('emits per-resource limit actions from tenant limit records', function (): void {
    $tenant = tenantLimitDialplanTenant();
    TenantLimit::factory()->create([
        'tenant_id' => $tenant->id,
        'resource' => 'local_extension',
        'soft_limit' => 5,
        'hard_limit' => 12,
    ]);
    TenantLimit::factory()->create([
        'tenant_id' => $tenant->id,
        'resource' => 'outbound_calls',
        'soft_limit' => 0,
        'hard_limit' => 25,
    ]);
    tenantLimitDialplanLocalExtension($tenant);

    $response = tenantLimitDialplan(app(DialplanContext::class)->internal((string) $tenant->id), '2000');

    $response->assertOk()
        ->assertSee('application="limit" data="hiredis default pbx:${domain_name}:local_extension:active 12"', false)
        ->assertSee('application="limit" data="hiredis default pbx:${domain_name}:outbound_calls:active 25"', false)
        // Soft limits are reserved and never emitted.
        ->assertDontSee(':soft', false)
        // The config fallback must not ride along when records exist.
        ->assertDontSee('active 100000', false);
});

it('keeps the local_extension config fallback when only other resources are limited', function (): void {
    $tenant = tenantLimitDialplanTenant();
    TenantLimit::factory()->create([
        'tenant_id' => $tenant->id,
        'resource' => 'outbound_calls',
        'soft_limit' => 0,
        'hard_limit' => 25,
    ]);
    tenantLimitDialplanLocalExtension($tenant);

    tenantLimitDialplan(app(DialplanContext::class)->internal((string) $tenant->id), '2000')
        ->assertOk()
        ->assertSee('application="limit" data="hiredis default pbx:${domain_name}:local_extension:active 100000"', false)
        ->assertSee('application="limit" data="hiredis default pbx:${domain_name}:outbound_calls:active 25"', false);
});

it('picks up limit changes immediately through the dialplan cache', function (): void {
    config(['freeswitch.xml_handler.dialplan_cache_ttl' => 60]);
    Cache::store('array')->flush();
    $tenant = tenantLimitDialplanTenant();
    tenantLimitDialplanLocalExtension($tenant);

    // Cache the fallback limit output.
    tenantLimitDialplan(app(DialplanContext::class)->internal((string) $tenant->id), '2000')
        ->assertOk()
        ->assertSee('active 100000', false);

    // A service write must invalidate the cached dialplan immediately.
    app(TenantLimitService::class)->create([
        'tenant_id' => $tenant->id,
        'resource' => 'local_extension',
        'soft_limit' => 1,
        'hard_limit' => 7,
    ]);

    tenantLimitDialplan(app(DialplanContext::class)->internal((string) $tenant->id), '2000')
        ->assertOk()
        ->assertSee('active 7', false)
        ->assertDontSee('active 100000', false);
});

it('falls back to the config max when the tenant has no limit records', function (): void {
    $tenant = tenantLimitDialplanTenant();
    tenantLimitDialplanLocalExtension($tenant);

    tenantLimitDialplan(app(DialplanContext::class)->internal((string) $tenant->id), '2000')
        ->assertOk()
        ->assertSee('application="limit" data="hiredis default pbx:${domain_name}:local_extension:active 100000"', false);
});
