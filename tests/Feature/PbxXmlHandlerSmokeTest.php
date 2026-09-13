<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\DialplanContext;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

const PBX_XML_HANDLER_SMOKE_CSV_PATH = 'storage/framework/testing/load-tests/xml-handler-smoke-sipp-users.csv';

beforeEach(function (): void {
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.dialplan_cache_store' => 'array',
        'freeswitch.xml_handler.dialplan_cache_ttl' => 60,
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 60,
        'freeswitch.xml_handler.hiredis_limit_enabled' => false,
        'freeswitch.xml_handler.hiredis_marker_enabled' => false,
    ]);

    Cache::store('array')->flush();
    File::delete(base_path(PBX_XML_HANDLER_SMOKE_CSV_PATH));
    app(TenantManager::class)->clear();
});

afterEach(function (): void {
    File::delete(base_path(PBX_XML_HANDLER_SMOKE_CSV_PATH));
});

it('smoke tests seeded internal inbound outbound and cached dialplan XML paths', function (): void {
    $this->artisan('pbx:load-test:seed', [
        '--tenant' => 'xml-handler-ci-smoke',
        '--domain' => 'xml-handler-ci-smoke.test',
        '--extensions' => '3',
        '--start' => '4300',
        '--password' => 'Smoke1234!',
        '--output' => PBX_XML_HANDLER_SMOKE_CSV_PATH,
        '--reset' => true,
    ])->assertSuccessful();

    $tenant = Tenant::where('slug', 'xml-handler-ci-smoke')->firstOrFail();
    $dialplanContext = app(DialplanContext::class);
    $internalContext = $dialplanContext->internal((string) $tenant->id);
    $publicContext = $dialplanContext->public((string) $tenant->id);

    dialplanXml($internalContext, '4301', '4300')
        ->assertOk()
        ->assertSee('<section name="dialplan">', false)
        ->assertSee('<context name="'.$internalContext.'">', false)
        ->assertSee('<extension name="domain-variables" continue="true">', false)
        ->assertSee('<extension name="is_local" continue="true">', false)
        ->assertSee('<extension name="local_extension">', false)
        ->assertDontSee('application="limit" data="hiredis default pbx:${domain_name}:local_extension:active 100000"', false)
        ->assertDontSee('application="hiredis_raw" data="default set pbx:mod_hiredis:last_call:${uuid} ${caller_id_number}-&gt;${destination_number}"', false)
        ->assertSee('data="${sofia_contact($1@${domain_name})}"', false)
        ->assertSee('local_extension', false);

    config([
        'freeswitch.xml_handler.hiredis_limit_enabled' => true,
        'freeswitch.xml_handler.hiredis_marker_enabled' => true,
    ]);
    Cache::store('array')->flush();

    dialplanXml($internalContext, '4301', '4300')
        ->assertOk()
        ->assertSee('application="limit" data="hiredis default pbx:${domain_name}:local_extension:active 100000"', false)
        ->assertSee('application="hiredis_raw" data="default set pbx:mod_hiredis:last_call:${uuid} ${caller_id_number}-&gt;${destination_number}"', false);

    dialplanXml($publicContext, '15551230000', '15551239999')
        ->assertOk()
        ->assertSee('<context name="'.$publicContext.'">', false)
        ->assertSee('inbound_SIPp inbound DID to first extension', false)
        ->assertSee('user/4300@xml-handler-ci-smoke.test', false);

    dialplanXml($internalContext, '91555123000', '4300')
        ->assertOk()
        ->assertSee('outbound_SIPp outbound 9-prefix', false)
        ->assertSee('sofia/external/$1@127.0.0.1:5088', false);

    $cacheMissResponse = dialplanXml($internalContext, '4302', '4300')
        ->assertOk()
        ->getContent();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $cacheHitResponse = dialplanXml($internalContext, '4302', '4300')
        ->assertOk()
        ->getContent();

    expect($cacheHitResponse)->toBe($cacheMissResponse)
        ->and($queries)->toBe([]);
});

function dialplanXml(string $context, string $destination, string $callerId): TestResponse
{
    return get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => $destination,
        'Caller-Caller-ID-Number' => $callerId,
    ]));
}
