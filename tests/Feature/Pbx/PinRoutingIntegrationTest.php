<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\DialplanContext;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Modules\PinNumbers\Models\PinNumber;
use Modules\PinNumbers\Services\PinNumberService;

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
 * Create a tenant for a PIN routing scenario.
 */
function pinRoutingTenant(): Tenant
{
    return Tenant::factory()->create(['enabled' => true]);
}

/**
 * Request the dialplan section of the real XML handler endpoint.
 */
function pinRoutingDialplan(string $context, string $destination): TestResponse
{
    return get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => $destination,
        'Caller-Caller-ID-Number' => '2001',
    ]));
}

it('returns null when the tenant has no enabled pins', function (): void {
    $tenant = pinRoutingTenant();

    $xml = app(PinNumberService::class)->generateDialplanXml((int) $tenant->id, 'tenant_x_internal', '1234');

    expect($xml)->toBeNull();
});

it('excludes empty pin numbers from the validation union', function (): void {
    $tenant = pinRoutingTenant();
    PinNumber::factory()->create(['tenant_id' => $tenant->id, 'pin_number' => '1234', 'enabled' => true]);
    PinNumber::factory()->create(['tenant_id' => $tenant->id, 'pin_number' => '', 'enabled' => true]);

    $xml = app(PinNumberService::class)->generateDialplanXml((int) $tenant->id, 'tenant_x_internal', '1234');

    expect($xml)->toContain('expression="^(1234)$"');
});

it('emits the interactive pin access and destination extensions', function (): void {
    $tenant = pinRoutingTenant();
    PinNumber::factory()->create(['tenant_id' => $tenant->id, 'pin_number' => '1234', 'enabled' => true]);
    PinNumber::factory()->create(['tenant_id' => $tenant->id, 'pin_number' => '5678', 'enabled' => true]);
    PinNumber::factory()->create(['tenant_id' => $tenant->id, 'pin_number' => '9999', 'enabled' => false]);

    $xml = app(PinNumberService::class)->generateDialplanXml((int) $tenant->id, 'tenant_x_internal', '1234');

    expect($xml)
        ->toContain('<extension name="pin_access">')
        ->toContain('expression="^\*97$"')
        ->toContain('application="answer"')
        ->toContain('application="play_and_get_digits"')
        ->toContain('phrase:pin_number_enter')
        ->toContain('pin_attempt')
        ->toContain('application="transfer"')
        ->toContain('<extension name="pin_destination">')
        // Enabled pins only, regex-escaped union.
        ->toContain('expression="^(1234|5678)$"')
        ->not->toContain('9999');
});

it('serves the pin flow end to end through the xml handler', function (): void {
    $tenant = pinRoutingTenant();
    $context = app(DialplanContext::class)->internal((string) $tenant->id);
    PinNumber::factory()->create(['tenant_id' => $tenant->id, 'pin_number' => '1234', 'enabled' => true]);

    pinRoutingDialplan($context, '*97')
        ->assertOk()
        ->assertSee('pin_access', false)
        ->assertSee('pin_destination', false)
        ->assertSee('phrase:pin_number_enter', false)
        ->assertSee('expression="^(1234)$"', false)
        ->assertDontSee('NO_ROUTE_DESTINATION');
});

it('picks up pin changes immediately despite a long dialplan cache ttl', function (): void {
    config([
        'freeswitch.xml_handler.dialplan_cache_ttl' => 60,
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 60,
    ]);
    Cache::store('array')->flush();

    $tenant = pinRoutingTenant();
    $context = app(DialplanContext::class)->internal((string) $tenant->id);
    $pin = PinNumber::factory()->create(['tenant_id' => $tenant->id, 'pin_number' => '1234', 'enabled' => true]);

    pinRoutingDialplan($context, '*97')->assertOk()->assertSee('pin_access', false);

    // Disabling a compromised PIN must not wait out the cache TTL.
    app(PinNumberService::class)->update($pin, ['enabled' => false]);

    pinRoutingDialplan($context, '*97')->assertOk()->assertDontSee('pin_access', false);
});

it('xml escapes the trigger pattern in the pin access expression', function (): void {
    Config::set('freeswitch.xml_handler.pin_trigger', '*&97');
    $tenant = pinRoutingTenant();
    PinNumber::factory()->create(['tenant_id' => $tenant->id, 'pin_number' => '1234', 'enabled' => true]);

    $xml = app(PinNumberService::class)->generateDialplanXml((int) $tenant->id, 'tenant_x_internal', '1234');

    expect($xml)
        ->toContain('expression="^\*&amp;97$"')
        ->not->toContain('^\*&97$');
});

it('xml escapes the context embedded in transfer actions', function (): void {
    $tenant = pinRoutingTenant();
    PinNumber::factory()->create(['tenant_id' => $tenant->id, 'pin_number' => '1234', 'enabled' => true]);

    $xml = app(PinNumberService::class)->generateDialplanXml((int) $tenant->id, 'tenant_1_internal"><evil', '1234');

    expect($xml)
        ->toContain('XML tenant_1_internal&quot;&gt;&lt;evil')
        ->not->toContain('internal"><evil');
});
