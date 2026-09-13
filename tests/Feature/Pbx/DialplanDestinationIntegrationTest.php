<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\DestinationResolver;
use App\Services\DialplanContext;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Modules\Bridges\Models\Bridge;
use Modules\CallCenters\Models\Queue;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Models\DialplanDetail;
use Modules\Extensions\Models\Extension;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Models\RingGroupExtension;
use Modules\Voicemails\Models\Voicemail;

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
    ]);
    Cache::store('array')->flush();
    app(TenantManager::class)->clear();
});

/**
 * Create a tenant for a destination integration scenario.
 */
function destinationIntegrationTenant(): Tenant
{
    return Tenant::factory()->create(['enabled' => true]);
}

/**
 * Create an enabled inbound route for a destination integration scenario.
 */
function destinationIntegrationRoute(array $overrides = []): InboundRoute
{
    return InboundRoute::factory()->create(array_merge([
        'destination_number' => '8005551212',
        'priority' => 1,
        'enabled' => true,
    ], $overrides));
}

/**
 * Request the dialplan section of the real XML handler endpoint.
 */
function destinationIntegrationDialplan(string $context, string $destination, string $callerId = '2001'): TestResponse
{
    return get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => $destination,
        'Caller-Caller-ID-Number' => $callerId,
    ]));
}

it('resolves an extension destination through the full dialplan path', function (): void {
    $tenant = destinationIntegrationTenant();
    Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '2000',
        'enabled' => true,
    ]);
    destinationIntegrationRoute([
        'tenant_id' => $tenant->id,
        'name' => 'support_did',
        'action' => DestinationResolver::KIND_EXTENSION,
        'action_data' => '2000',
    ]);

    destinationIntegrationDialplan(app(DialplanContext::class)->public((string) $tenant->id), '8005551212')
        ->assertOk()
        ->assertSee('support_did', false)
        ->assertSee('application="bridge"', false)
        ->assertSee('data="user/2000"', false)
        ->assertDontSee('NO_ROUTE_DESTINATION');
});

it('resolves an external destination through the full dialplan path', function (): void {
    $tenant = destinationIntegrationTenant();
    destinationIntegrationRoute([
        'tenant_id' => $tenant->id,
        'name' => 'external_did',
        'action' => DestinationResolver::KIND_EXTERNAL,
        'action_data' => '15551230000',
    ]);

    destinationIntegrationDialplan(app(DialplanContext::class)->public((string) $tenant->id), '8005551212')
        ->assertOk()
        ->assertSee('external_did', false)
        ->assertSee('application="bridge"', false)
        ->assertSee('data="sofia/external/15551230000"', false)
        ->assertDontSee('NO_ROUTE_DESTINATION');
});

it('resolves an ivr destination through the full dialplan path', function (): void {
    $tenant = destinationIntegrationTenant();
    IvrMenu::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'main_menu',
        'enabled' => true,
    ]);
    destinationIntegrationRoute([
        'tenant_id' => $tenant->id,
        'name' => 'ivr_did',
        'action' => DestinationResolver::KIND_IVR,
        'action_data' => 'main_menu',
    ]);

    destinationIntegrationDialplan(app(DialplanContext::class)->public((string) $tenant->id), '8005551212')
        ->assertOk()
        ->assertSee('ivr_did', false)
        ->assertSee('application="transfer"', false)
        ->assertSee('data="main_menu XML main_menu"', false)
        ->assertDontSee('NO_ROUTE_DESTINATION');
});

it('resolves a voicemail destination through the full dialplan path', function (): void {
    $tenant = destinationIntegrationTenant();
    Voicemail::factory()->create([
        'tenant_id' => $tenant->id,
        'mailbox' => '2000',
        'enabled' => true,
    ]);
    destinationIntegrationRoute([
        'tenant_id' => $tenant->id,
        'name' => 'voicemail_did',
        'action' => DestinationResolver::KIND_VOICEMAIL,
        'action_data' => '2000',
    ]);

    destinationIntegrationDialplan(app(DialplanContext::class)->public((string) $tenant->id), '8005551212')
        ->assertOk()
        ->assertSee('voicemail_did', false)
        ->assertSee('application="voicemail"', false)
        // ${domain} stays literal in the XML; FreeSWITCH substitutes it.
        ->assertSee('data="default ${domain} 2000"', false)
        ->assertDontSee('NO_ROUTE_DESTINATION');
});

it('resolves a conference destination through the full dialplan path', function (): void {
    $tenant = destinationIntegrationTenant();
    Bridge::factory()->create([
        'tenant_id' => $tenant->id,
        'bridge_name' => 'support_conf',
        'pin_number' => '1234',
        'enabled' => true,
    ]);
    destinationIntegrationRoute([
        'tenant_id' => $tenant->id,
        'name' => 'conference_did',
        'action' => DestinationResolver::KIND_CONFERENCE,
        'action_data' => 'support_conf',
    ]);

    destinationIntegrationDialplan(app(DialplanContext::class)->public((string) $tenant->id), '8005551212')
        ->assertOk()
        ->assertSee('conference_did', false)
        ->assertSee('application="conference"', false)
        ->assertSee('data="support_conf@default+pin_1234"', false)
        ->assertDontSee('NO_ROUTE_DESTINATION');
});

it('resolves a call center queue destination through the full dialplan path', function (): void {
    $tenant = destinationIntegrationTenant();
    Queue::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'sales_queue',
        'enabled' => true,
    ]);
    destinationIntegrationRoute([
        'tenant_id' => $tenant->id,
        'name' => 'queue_did',
        'action' => DestinationResolver::KIND_QUEUE,
        'action_data' => 'sales_queue',
    ]);

    destinationIntegrationDialplan(app(DialplanContext::class)->public((string) $tenant->id), '8005551212')
        ->assertOk()
        ->assertSee('queue_did', false)
        ->assertSee('application="callcenter"', false)
        ->assertSee('data="sales_queue"', false)
        ->assertDontSee('NO_ROUTE_DESTINATION');
});

it('resolves a ring group destination through the full dialplan path', function (): void {
    $tenant = destinationIntegrationTenant();
    $group = RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'support_team',
        'strategy' => 'ring-all',
        'enabled' => true,
    ]);
    // The resolver bridges to each member's extension UUID, in position order.
    RingGroupExtension::factory()->create([
        'ring_group_id' => $group->id,
        'extension_uuid' => 'ext-uuid-1',
        'position' => 1,
    ]);
    RingGroupExtension::factory()->create([
        'ring_group_id' => $group->id,
        'extension_uuid' => 'ext-uuid-2',
        'position' => 2,
    ]);
    destinationIntegrationRoute([
        'tenant_id' => $tenant->id,
        'name' => 'ring_did',
        'action' => DestinationResolver::KIND_RING_GROUP,
        'action_data' => (string) $group->id,
    ]);

    destinationIntegrationDialplan(app(DialplanContext::class)->public((string) $tenant->id), '8005551212')
        ->assertOk()
        ->assertSee('ring_did', false)
        ->assertSee('application="bridge"', false)
        ->assertSee('data="user/ext-uuid-1,user/ext-uuid-2"', false)
        ->assertDontSee('NO_ROUTE_DESTINATION');
});

it('fails closed when a route points at another tenant destination', function (): void {
    $tenantA = destinationIntegrationTenant();
    $tenantB = destinationIntegrationTenant();
    Extension::factory()->create([
        'tenant_id' => $tenantB->id,
        'extension_number' => '2000',
        'enabled' => true,
    ]);
    // The route belongs to tenant A, but its identifier resolves only in
    // tenant B: the resolver is genuinely invoked with tenant A and must
    // reject the foreign identifier (a route-query filter alone could not
    // hide a tenant-scope regression in the resolver).
    destinationIntegrationRoute([
        'tenant_id' => $tenantA->id,
        'name' => 'foreign_did',
        'action' => DestinationResolver::KIND_EXTENSION,
        'action_data' => '2000',
    ]);

    destinationIntegrationDialplan(app(DialplanContext::class)->public((string) $tenantA->id), '8005551212')
        ->assertOk()
        ->assertDontSee('foreign_did')
        ->assertDontSee('data="user/2000"', false)
        ->assertSee('NO_ROUTE_DESTINATION');
});

it('fails closed when the destination extension is disabled', function (): void {
    $tenant = destinationIntegrationTenant();
    Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '2000',
        'enabled' => false,
    ]);
    destinationIntegrationRoute([
        'tenant_id' => $tenant->id,
        'name' => 'disabled_ext_did',
        'action' => DestinationResolver::KIND_EXTENSION,
        'action_data' => '2000',
    ]);

    destinationIntegrationDialplan(app(DialplanContext::class)->public((string) $tenant->id), '8005551212')
        ->assertOk()
        ->assertDontSee('disabled_ext_did')
        ->assertDontSee('data="user/2000"', false)
        ->assertSee('NO_ROUTE_DESTINATION');
});

it('skips custom-action routes because custom is not user selectable', function (): void {
    $tenant = destinationIntegrationTenant();
    destinationIntegrationRoute([
        'tenant_id' => $tenant->id,
        'name' => 'custom_did',
        'action' => 'custom',
        'action_data' => 'playback /tmp/hello.wav',
    ]);

    destinationIntegrationDialplan(app(DialplanContext::class)->public((string) $tenant->id), '8005551212')
        ->assertOk()
        ->assertDontSee('custom_did')
        ->assertDontSee('/tmp/hello.wav', false)
        ->assertSee('NO_ROUTE_DESTINATION');
});

it('scopes hiredis limit and marker actions to local extension dialing only', function (): void {
    config([
        'freeswitch.xml_handler.hiredis_limit_enabled' => true,
        'freeswitch.xml_handler.hiredis_marker_enabled' => true,
    ]);
    Cache::store('array')->flush();

    $tenant = destinationIntegrationTenant();
    $internalContext = app(DialplanContext::class)->internal((string) $tenant->id);

    // A base local_extension dialplan: the only path the handler enriches
    // with opt-in mod_hiredis actions (verified against the default
    // provisioner's localExtensionDetails()).
    $dialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'local_extension',
        'context' => $internalContext,
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

    // The same tenant also routes a DID to an extension through the resolver.
    Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '2000',
        'enabled' => true,
    ]);
    destinationIntegrationRoute([
        'tenant_id' => $tenant->id,
        'name' => 'hiredis_did',
        'action' => DestinationResolver::KIND_EXTENSION,
        'action_data' => '2000',
    ]);

    // A local_extension dialplan also exists in the PUBLIC context, so the
    // public response contains the hiredis actions in that dialplan's block.
    // This keeps the block-level assertion below discriminating: a handler
    // regression that enriched the resolver route would be caught.
    $publicDialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'local_extension',
        'context' => app(DialplanContext::class)->public((string) $tenant->id),
        'order' => 30,
        'enabled' => true,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $publicDialplan->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^([1-9][0-9]{2,5})$',
        'action' => 'bridge',
        'data' => '${sofia_contact($1@${domain_name})}',
        'order' => 30,
    ]);

    // Direct extension dialing: the limit and marker actions ride along with
    // the local-extension bridge. Needles copied verbatim from the smoke
    // suite; > is XML-escaped as &gt;.
    destinationIntegrationDialplan($internalContext, '2000')
        ->assertOk()
        ->assertSee('application="limit" data="hiredis default pbx:${domain_name}:local_extension:active 100000"', false)
        ->assertSee('application="hiredis_raw" data="default set pbx:mod_hiredis:last_call:${uuid} ${caller_id_number}-&gt;${destination_number}"', false)
        ->assertSee('data="${sofia_contact($1@${domain_name})}"', false);

    // DID routing resolves through DestinationResolver and never inherits
    // the hiredis actions, even when they are enabled and a local_extension
    // dialplan exists in the same context: the hiredis actions live in that
    // dialplan's block, never in the resolver route's extension.
    $didResponse = destinationIntegrationDialplan(app(DialplanContext::class)->public((string) $tenant->id), '8005551212');
    $didResponse
        ->assertOk()
        ->assertSee('hiredis_did', false)
        ->assertSee('application="bridge"', false)
        ->assertSee('data="user/2000"', false)
        ->assertSee('application="limit" data="hiredis default pbx:${domain_name}:local_extension:active 100000"', false);

    $document = new DOMDocument;
    $document->loadXML($didResponse->getContent());
    $xpath = new DOMXPath($document);
    $routeExtensions = $xpath->query("//extension[contains(@name, 'hiredis_did')]");

    expect($routeExtensions->length)->toBe(1);
    $routeBlock = $document->saveXML($routeExtensions->item(0));
    // The extension name itself contains "hiredis" (inbound_hiredis_did), so
    // the scoping check targets the limit/marker applications, not the word.
    expect($routeBlock)
        ->toContain('application="bridge"')
        ->toContain('data="user/2000"')
        ->not->toContain('application="limit"')
        ->not->toContain('application="hiredis_raw"');
});
