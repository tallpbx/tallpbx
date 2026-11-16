<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\DialplanContext;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Models\DialplanDetail;
use Modules\Extensions\Models\Extension;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Models\RingGroupExtension;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Dialplan Tenant Identity
|--------------------------------------------------------------------------
|
| Every channel that executes a tenant context is stamped with a
| channel-local `tenant_id`, and every directly originated B-leg carries a
| destination-owned `tenant_id` in its own dial-string variable block.
|
*/

beforeEach(function (): void {
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.dialplan_cache_store' => 'array',
        'freeswitch.xml_handler.dialplan_cache_ttl' => 0,
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 0,
        'freeswitch.xml_handler.directory_cache_ttl' => 0,
        'freeswitch.xml_handler.log_timing' => false,
        'freeswitch.xml_handler.hiredis_limit_enabled' => false,
        'freeswitch.xml_handler.hiredis_marker_enabled' => false,
    ]);
    Cache::store('array')->flush();
    app(TenantManager::class)->clear();
});

/**
 * Request the dialplan section of the real XML handler endpoint.
 */
function dialplanIdentityRequest(string $context, string $destination = '2000'): TestResponse
{
    return get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => $destination,
        'Caller-Caller-ID-Number' => '2001',
    ]));
}

/**
 * Create a tenant owning a shared domain used by the identity cases.
 */
function dialplanIdentityTenant(string $domain = 'shared.test'): Tenant
{
    $tenant = Tenant::factory()->create(['enabled' => true]);
    TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => $domain]);

    return $tenant;
}

it('stamps the executing channel with its tenant in internal and public contexts', function (): void {
    $first = dialplanIdentityTenant();
    $second = dialplanIdentityTenant();
    $contexts = app(DialplanContext::class);

    foreach ([$first, $second] as $tenant) {
        foreach ([$contexts->internal((string) $tenant->id), $contexts->public((string) $tenant->id)] as $context) {
            dialplanIdentityRequest($context)
                ->assertOk()
                ->assertSee('<action application="set" data="tenant_id='.$tenant->id.'"/>', false);
        }
    }
});

it('stamps a direct local-extension bridge with the destination tenant', function (): void {
    $tenant = dialplanIdentityTenant();
    $internal = app(DialplanContext::class)->internal((string) $tenant->id);

    $dialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'local_extension',
        'context' => $internal,
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

    dialplanIdentityRequest($internal)
        ->assertOk()
        ->assertSee('data="${sofia_contact($1@${domain_name})}[tenant_id='.$tenant->id.']"', false);
});

it('leaves the stored dialplan detail unchanged while stamping the rendered bridge', function (): void {
    $tenant = dialplanIdentityTenant();
    $internal = app(DialplanContext::class)->internal((string) $tenant->id);

    $dialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'local_extension',
        'context' => $internal,
        'order' => 30,
        'enabled' => true,
    ]);
    $detail = DialplanDetail::factory()->create([
        'dialplan_id' => $dialplan->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^([1-9][0-9]{2,5})$',
        'action' => 'bridge',
        'data' => '${sofia_contact($1@${domain_name})}',
        'order' => 30,
    ]);

    dialplanIdentityRequest($internal)->assertOk();

    expect($detail->fresh()->data)->toBe('${sofia_contact($1@${domain_name})}');
});

it('keeps the opt-in hiredis actions beside the stamped bridge', function (): void {
    config([
        'freeswitch.xml_handler.hiredis_limit_enabled' => true,
        'freeswitch.xml_handler.hiredis_marker_enabled' => true,
    ]);

    $tenant = dialplanIdentityTenant();
    $internal = app(DialplanContext::class)->internal((string) $tenant->id);

    $dialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'local_extension',
        'context' => $internal,
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

    dialplanIdentityRequest($internal)
        ->assertOk()
        ->assertSee('application="limit" data="hiredis default pbx:${domain_name}:local_extension:active 100000"', false)
        ->assertSee('application="hiredis_raw"', false)
        ->assertSee('data="${sofia_contact($1@${domain_name})}[tenant_id='.$tenant->id.']"', false);
});

it('stamps each ring-group leg with the destination tenant, never a global prefix', function (): void {
    $tenant = dialplanIdentityTenant();
    $internal = app(DialplanContext::class)->internal((string) $tenant->id);

    $firstExtension = Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '2101',
        'enabled' => true,
    ]);
    $secondExtension = Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '2102',
        'enabled' => true,
    ]);

    $group = RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'identity_team',
        'strategy' => 'enterprise',
        'enabled' => true,
    ]);
    RingGroupExtension::factory()->create([
        'ring_group_id' => $group->id,
        'extension_uuid' => $firstExtension->id,
        'position' => 1,
    ]);
    RingGroupExtension::factory()->create([
        'ring_group_id' => $group->id,
        'extension_uuid' => $secondExtension->id,
        'position' => 2,
    ]);

    $response = dialplanIdentityRequest($internal);
    $xml = (string) $response->getContent();

    $response->assertOk();

    // One variable block per leg, each naming the destination tenant.
    expect(substr_count($xml, '[tenant_id='.$tenant->id.']'))->toBe(2)
        ->and($xml)->toContain('${sofia_contact(2101@${domain_name})}[tenant_id='.$tenant->id.']')
        ->and($xml)->toContain('${sofia_contact(2102@${domain_name})}[tenant_id='.$tenant->id.']')
        // The shared outer block must not carry the tenant: it would leak the
        // caller's tenant onto every leg of a cross-tenant bridge.
        ->and($xml)->not->toContain('ignore_early_media=true,tenant_id');
});
