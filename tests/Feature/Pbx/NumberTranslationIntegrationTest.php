<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\DialplanContext;
use App\Services\DialplanXmlCollector;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Modules\NumberTranslations\Models\NumberTranslation;
use Modules\NumberTranslations\Services\NumberTranslationServiceInterface;

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
 * Create a tenant for a number translation scenario.
 */
function numberTranslationTenant(): Tenant
{
    return Tenant::factory()->create(['enabled' => true]);
}

/**
 * Request the dialplan section of the real XML handler endpoint.
 */
function numberTranslationDialplan(string $context, string $destination): TestResponse
{
    return get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => $destination,
        'Caller-Caller-ID-Number' => '2001',
    ]));
}

it('rewrites an inbound DID before routing decisions', function (): void {
    $tenant = numberTranslationTenant();
    NumberTranslation::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'did-normalize',
        'match_pattern' => '^8005551212$',
        'replace_pattern' => '15551230000',
        'direction' => 'inbound',
        'order' => 1,
        'enabled' => true,
    ]);
    $context = app(DialplanContext::class)->public((string) $tenant->id);

    // The collector must see the TRANSLATED destination: the dialplan XML
    // body itself is destination-independent (conditions match at runtime),
    // so the translation's observable effect is the destination handed to
    // the collector.
    $collector = Mockery::mock(DialplanXmlCollector::class);
    $collector->shouldReceive('collect')->once()->with((int) $tenant->id, $context, '15551230000')->andReturn('');
    app()->instance(DialplanXmlCollector::class, $collector);

    numberTranslationDialplan($context, '8005551212')->assertOk();
});

it('leaves outbound rules inert on the public context', function (): void {
    $tenant = numberTranslationTenant();
    NumberTranslation::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'outbound-only',
        'match_pattern' => '^8005551212$',
        'replace_pattern' => '15551230000',
        'direction' => 'outbound',
        'order' => 1,
        'enabled' => true,
    ]);
    $context = app(DialplanContext::class)->public((string) $tenant->id);

    // Outbound rules never apply to public-context requests: the collector
    // receives the original destination unchanged.
    $collector = Mockery::mock(DialplanXmlCollector::class);
    $collector->shouldReceive('collect')->once()->with((int) $tenant->id, $context, '8005551212')->andReturn('');
    app()->instance(DialplanXmlCollector::class, $collector);

    numberTranslationDialplan($context, '8005551212')->assertOk();
});

it('keeps the request healthy when a rule has an invalid regex', function (): void {
    $tenant = numberTranslationTenant();
    NumberTranslation::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'broken',
        'match_pattern' => '(',
        'replace_pattern' => 'x',
        'direction' => 'inbound',
        'order' => 1,
        'enabled' => true,
    ]);
    $context = app(DialplanContext::class)->public((string) $tenant->id);

    // The broken rule is skipped: the destination passes through unchanged.
    $collector = Mockery::mock(DialplanXmlCollector::class);
    $collector->shouldReceive('collect')->once()->with((int) $tenant->id, $context, '8005551212')->andReturn('');
    app()->instance(DialplanXmlCollector::class, $collector);

    numberTranslationDialplan($context, '8005551212')->assertOk();
});

it('picks up translation changes immediately through the dialplan cache', function (): void {
    config(['freeswitch.xml_handler.dialplan_cache_ttl' => 60]);
    Cache::store('array')->flush();
    $tenant = numberTranslationTenant();
    $context = app(DialplanContext::class)->public((string) $tenant->id);

    $collector = Mockery::mock(DialplanXmlCollector::class);
    $collector->shouldReceive('collect')->once()->with((int) $tenant->id, $context, '8005551212')->andReturn('');
    app()->instance(DialplanXmlCollector::class, $collector);

    // Cache the untranslated routing for the DID.
    numberTranslationDialplan($context, '8005551212')->assertOk();

    // A service write must invalidate the cached dialplan immediately: the
    // second request misses the cache and reaches the collector with the
    // translated destination.
    app(NumberTranslationServiceInterface::class)->create([
        'tenant_id' => $tenant->id,
        'name' => 'did-normalize',
        'match_pattern' => '^8005551212$',
        'replace_pattern' => '15551230000',
        'direction' => 'inbound',
        'order' => 1,
        'enabled' => true,
    ]);

    $collector->shouldReceive('collect')->once()->with((int) $tenant->id, $context, '15551230000')->andReturn('');
    numberTranslationDialplan($context, '8005551212')->assertOk();
});

it('materializes the inbound rewrite in the served dialplan xml', function (): void {
    $tenant = numberTranslationTenant();
    NumberTranslation::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'did-normalize',
        'match_pattern' => '^8005551212$',
        'replace_pattern' => '15551230000',
        'direction' => 'inbound',
        'order' => 1,
        'enabled' => true,
    ]);

    // Without this action the rewrite never reaches FreeSWITCH: conditions
    // match the channel's untranslated destination_number at runtime.
    numberTranslationDialplan(app(DialplanContext::class)->public((string) $tenant->id), '8005551212')
        ->assertOk()
        ->assertSee('<action application="set" data="destination_number=15551230000"/>', false);
});

it('emits no destination rewrite action when no rule applies', function (): void {
    $tenant = numberTranslationTenant();

    numberTranslationDialplan(app(DialplanContext::class)->public((string) $tenant->id), '8005559999')
        ->assertOk()
        ->assertDontSee('application="set" data="destination_number=', false);
});
