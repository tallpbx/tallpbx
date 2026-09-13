<?php

declare(strict_types=1);

use App\Http\Controllers\Api\XmlHandlerController;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\DialplanContext;
use App\Services\DialplanXmlCollector;
use App\Services\TenantDefaultsService;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\AccessControls\Models\AccessControl;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Models\DialplanDetail;
use Modules\Extensions\Models\Extension;
use Modules\ExtensionSettings\Models\ExtensionSetting;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\RingGroups\Models\RingGroup;
use Modules\SipAccounts\Models\SipAccount;
use Modules\SipProfiles\Models\SipProfile;

beforeEach(function () {
    // Disable XML handler auth for most tests to avoid token requirement
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

// ─── Directory Section ──────────────────────────────────────────

it('returns XML for directory domain lookup', function () {
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'example.com',
        'key_value' => 'example.com',
    ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('example.com')
        ->assertSee('<section name="directory">', false);
});

it('returns XML for directory user lookup with not-found response', function () {
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'user',
        'key_value' => '1001',
        'sip_auth_username' => 'user1001',
    ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('User not found: user1001', false);
});

it('handles directory request with POST method', function () {
    $response = $this->post('/api/v1/xml-handler', [
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'pbx.local',
        'key_value' => 'pbx.local',
    ]);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
});

// ─── Dialplan Section ───────────────────────────────────────────

it('returns XML for dialplan lookup', function () {
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => 'public',
        'Caller-Destination-Number' => '999',
        'Caller-Caller-ID-Number' => '1001',
    ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<section name="dialplan">', false)
        ->assertSee('public')
        ->assertSee('NO_ROUTE_DESTINATION');
});

it('does not write successful XML handler debug logs by default', function () {
    Log::spy();

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => 'tenant_123_internal',
        'Caller-Destination-Number' => '2000',
        'Caller-Caller-ID-Number' => '2001',
    ]));

    $response->assertOk();

    Log::shouldNotHaveReceived('debug');
});

it('writes successful XML handler debug logs when request logging is enabled', function () {
    config(['freeswitch.xml_handler.log_requests' => true]);
    Log::spy();

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => 'tenant_123_internal',
        'Caller-Destination-Number' => '2000',
        'Caller-Caller-ID-Number' => '2001',
    ]));

    $response->assertOk();

    Log::shouldHaveReceived('debug')->twice();
});

it('does not write XML handler timing logs by default', function () {
    Log::spy();

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => 'tenant_123_internal',
        'Caller-Destination-Number' => '2000',
        'Caller-Caller-ID-Number' => '2001',
    ]));

    $response->assertOk();

    Log::shouldNotHaveReceived('info', ['XML Handler timing.', Mockery::any()]);
});

it('writes safe XML handler timing logs when timing logging is enabled', function () {
    config(['freeswitch.xml_handler.log_timing' => true]);
    Log::spy();

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'token' => 'secret-token-that-must-not-be-logged',
        'Caller-Context' => 'tenant_123_internal',
        'Caller-Destination-Number' => '2000',
        'Caller-Caller-ID-Number' => '2001',
    ]));

    $response->assertOk();

    Log::shouldHaveReceived('info')
        ->once()
        ->with('XML Handler timing.', Mockery::on(function (array $context): bool {
            return isset($context['elapsed_ms'])
                && isset($context['db_query_count'])
                && isset($context['db_time_ms'])
                && isset($context['non_db_time_ms'])
                && is_int($context['db_query_count'])
                && $context['section'] === 'dialplan'
                && $context['status'] === 200
                && $context['context'] === 'tenant_123_internal'
                && $context['destination'] === '2000'
                && ! array_key_exists('token', $context);
        }));
});

it('caches repeated dialplan XML lookups when enabled', function () {
    config([
        'cache.default' => 'array',
        'freeswitch.xml_handler.dialplan_cache_store' => 'array',
        'freeswitch.xml_handler.dialplan_cache_ttl' => 60,
    ]);
    Cache::store('array')->flush();

    $collector = Mockery::mock(DialplanXmlCollector::class);
    $collector->shouldReceive('collect')
        ->once()
        ->with(123, 'tenant_123_internal', '2000')
        ->andReturn("      <extension name=\"cached_test\"/>\n");

    app()->instance(DialplanXmlCollector::class, $collector);

    $query = http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => 'tenant_123_internal',
        'Caller-Destination-Number' => '2000',
        'Caller-Caller-ID-Number' => '2001',
    ]);

    $this->get('/api/v1/xml-handler?'.$query)
        ->assertOk()
        ->assertSee('cached_test', false);

    $this->get('/api/v1/xml-handler?'.$query)
        ->assertOk()
        ->assertSee('cached_test', false);
});

// ─── Configuration Section ──────────────────────────────────────

it('returns XML for configuration lookup', function () {
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'ivr.conf',
        'key_value' => 'main_menu',
    ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<section name="configuration">', false)
        ->assertSee('ivr.conf');
});

it('serves switch core database settings for mariadb', function () {
    config([
        'freeswitch.switch.loglevel' => 'warning',
        'freeswitch.database.driver' => 'mariadb',
        'freeswitch.database.host' => '127.0.0.1',
        'freeswitch.database.port' => 3306,
        'freeswitch.database.database' => 'freeswitch',
        'freeswitch.database.username' => 'freeswitch',
        'freeswitch.database.password' => 'secret',
        'freeswitch.database.auto_create_schemas' => true,
        'freeswitch.database.core_non_sqlite_db_required' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'switch.conf',
    ]));

    $response->assertOk()
        ->assertSee('<configuration name="switch.conf" description="Core Configuration">', false)
        ->assertSee('<param name="loglevel" value="warning"/>', false)
        ->assertSee('<param name="core-db-dsn" value="mariadb://Server=127.0.0.1;Port=3306;Database=freeswitch;Uid=freeswitch;Pwd=secret;"/>', false)
        ->assertSee('<param name="auto-create-schemas" value="true"/>', false)
        ->assertSee('<param name="core-non-sqlite-db-required" value="true"/>', false);
});

it('falls back to notice for invalid switch log levels', function () {
    config([
        'freeswitch.switch.loglevel' => 'chatty',
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'switch.conf',
    ]));

    $response->assertOk()
        ->assertSee('<param name="loglevel" value="notice"/>', false)
        ->assertDontSee('chatty', false);
});

it('keeps sqlite as the default switch database when no external dsn is configured', function () {
    config([
        'freeswitch.database.driver' => 'sqlite',
        'freeswitch.database.dsn' => null,
        'freeswitch.database.core_db_name' => null,
        'freeswitch.database.auto_create_schemas' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'switch.conf',
    ]));

    $response->assertOk()
        ->assertSee('<configuration name="switch.conf" description="Core Configuration">', false)
        ->assertDontSee('core-db-dsn', false)
        ->assertDontSee('core-db-name', false)
        ->assertSee('<param name="auto-create-schemas" value="true"/>', false);
});

it('serves db module dsn settings when an external database is configured', function () {
    config([
        'freeswitch.database.driver' => 'mariadb',
        'freeswitch.database.host' => '127.0.0.1',
        'freeswitch.database.port' => 3306,
        'freeswitch.database.database' => 'freeswitch',
        'freeswitch.database.username' => 'freeswitch',
        'freeswitch.database.password' => 'secret',
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'db.conf',
    ]));

    $response->assertOk()
        ->assertSee('<configuration name="db.conf" description="LIMIT DB Configuration">', false)
        ->assertSee('<param name="odbc-dsn" value="mariadb://Server=127.0.0.1;Port=3306;Database=freeswitch;Uid=freeswitch;Pwd=secret;"/>', false);
});

it('serves fifo module dsn settings when an external database is configured', function () {
    config([
        'freeswitch.database.driver' => 'mariadb',
        'freeswitch.database.host' => '127.0.0.1',
        'freeswitch.database.port' => 3306,
        'freeswitch.database.database' => 'freeswitch',
        'freeswitch.database.username' => 'freeswitch',
        'freeswitch.database.password' => 'secret',
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'fifo.conf',
    ]));

    $response->assertOk()
        ->assertSee('<configuration name="fifo.conf" description="FIFO Configuration">', false)
        ->assertSee('<param name="odbc-dsn" value="mariadb://Server=127.0.0.1;Port=3306;Database=freeswitch;Uid=freeswitch;Pwd=secret;"/>', false);
});

it('serves local stream configuration for packaged music on hold files', function () {
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'local_stream.conf',
    ]));

    $response->assertOk()
        ->assertSee('<configuration name="local_stream.conf" description="Local Stream">', false)
        ->assertSee('<directory name="default" path="$${sounds_dir}/music/8000">', false)
        ->assertSee('<directory name="moh/8000" path="$${sounds_dir}/music/8000">', false)
        ->assertSee('<directory name="moh/16000" path="$${sounds_dir}/music/16000">', false)
        ->assertSee('<directory name="moh/32000" path="$${sounds_dir}/music/32000">', false)
        ->assertSee('<directory name="moh/48000" path="$${sounds_dir}/music/48000">', false)
        ->assertSee('<param name="timer-name" value="soft"/>', false);
});

it('serves sofia configuration with global settings and enabled tenant profiles', function () {
    config([
        'freeswitch.sofia.log_level' => 0,
        'freeswitch.sofia.auto_restart' => true,
        'freeswitch.sofia.debug_presence' => false,
        'freeswitch.sofia.max_reg_threads' => 2,
    ]);

    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->sipRealm()->create([
        'domain' => 'sip.example.com',
    ]);

    SipProfile::factory()->forTenant($tenant->id)->create([
        'name' => 'internal',
        'settings' => [
            'sip-port' => '5060',
            'sip-ip' => '$${local_ip_v4}',
            'rtp-ip' => '$${local_ip_v4}',
            'dtmf-type' => 'rfc2833',
        ],
    ]);

    SipProfile::factory()->forTenant($tenant->id)->disabled()->create([
        'name' => 'disabled',
        'settings' => [
            'sip-port' => '5070',
        ],
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'sofia.conf',
        'domain' => 'sip.example.com',
    ]));

    $response->assertOk()
        ->assertSee('<configuration name="sofia.conf" description="Sofia Endpoint">', false)
        ->assertSee('<param name="log-level" value="0"/>', false)
        ->assertSee('<param name="auto-restart" value="true"/>', false)
        ->assertSee('<param name="debug-presence" value="false"/>', false)
        ->assertSee('<param name="max-reg-threads" value="2"/>', false)
        ->assertSee('<profile name="internal">', false)
        ->assertSee('<param name="sip-port" value="5060"/>', false)
        ->assertSee('<param name="dtmf-type" value="rfc2833"/>', false)
        ->assertDontSee('<profile name="disabled">', false);
});

it('serves enabled sofia profiles when no tenant domain resolves during FreeSWITCH startup', function () {
    config([
        'freeswitch.sofia.log_level' => 0,
    ]);

    $tenant = Tenant::factory()->create();

    SipProfile::factory()->forTenant($tenant->id)->create([
        'name' => 'startup-internal',
        'settings' => [
            'sip-port' => '5060',
            'context' => 'tenant_'.$tenant->id.'_internal',
        ],
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'sofia.conf',
        'domain' => 'unknown.example.com',
    ]));

    $response->assertOk()
        ->assertSee('<global_settings>', false)
        ->assertSee('<profiles>', false)
        ->assertSee('<profile name="startup-internal">', false)
        ->assertSee('<param name="context" value="tenant_'.$tenant->id.'_internal"/>', false);
});

it('serves enabled sofia profiles when FreeSWITCH loads sofia without a tenant domain', function () {
    $tenant = Tenant::factory()->create();

    SipProfile::factory()->forTenant($tenant->id)->create([
        'name' => 'internal',
        'settings' => [
            'sip-port' => '5060',
            'context' => 'tenant_'.$tenant->id.'_internal',
        ],
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'sofia.conf',
    ]));

    $response->assertOk()
        ->assertSee('<profile name="internal">', false)
        ->assertSee('<param name="sip-port" value="5060"/>', false)
        ->assertSee('<param name="context" value="tenant_'.$tenant->id.'_internal"/>', false);
});

// ─── Unknown Section ────────────────────────────────────────────

it('returns 404 for unknown section', function () {
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'nonexistent',
    ]));

    $response->assertNotFound()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('Unknown section: nonexistent');
});

// ─── Default Section ────────────────────────────────────────────

it('defaults to directory section when no section is specified', function () {
    $response = $this->get('/api/v1/xml-handler');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<section name="directory">', false);
});

// ─── XML Structure ──────────────────────────────────────────────

it('returns valid XML with correct document type', function () {
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'test.local',
    ]));

    $xml = $response->content();

    expect($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>')
        ->and($xml)->toContain('<document type="freeswitch/xml">');
});

it('escapes XML special characters in output', function () {
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'evil<>&"\'domain.com',
    ]));

    $response->assertOk()
        ->assertDontSee('<evil', false); // The < should be escaped
});

// ─── Authentication ─────────────────────────────────────────────

it('returns XML when auth is enabled and correct token is provided via query', function () {
    config([
        'freeswitch.xml_handler.auth' => true,
        'freeswitch.xml_handler.token' => 'secret-token-123',
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'secure.local',
        'token' => 'secret-token-123',
    ]));

    $response->assertOk();
});

it('returns XML when auth is enabled and correct token is provided via bearer', function () {
    config([
        'freeswitch.xml_handler.auth' => true,
        'freeswitch.xml_handler.token' => 'secret-token-123',
    ]);

    $response = $this->withToken('secret-token-123')
        ->get('/api/v1/xml-handler?'.http_build_query([
            'section' => 'directory',
            'tag_name' => 'domain',
            'domain' => 'secure.local',
        ]));

    $response->assertOk();
});

it('returns 403 when auth is enabled and token is missing', function () {
    config([
        'freeswitch.xml_handler.auth' => true,
        'freeswitch.xml_handler.token' => 'secret-token-123',
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'secure.local',
    ]));

    $response->assertForbidden()
        ->assertSee('Access Denied');
});

it('returns 403 when auth is enabled and token is incorrect', function () {
    config([
        'freeswitch.xml_handler.auth' => true,
        'freeswitch.xml_handler.token' => 'correct-token',
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'secure.local',
        'token' => 'wrong-token',
    ]));

    $response->assertForbidden()
        ->assertSee('Access Denied');
});

it('authenticates via X-FS-Token header', function () {
    config([
        'freeswitch.xml_handler.auth' => true,
        'freeswitch.xml_handler.token' => 'header-token-abc',
    ]);

    $response = $this->withHeader('X-FS-Token', 'header-token-abc')
        ->get('/api/v1/xml-handler?'.http_build_query([
            'section' => 'directory',
            'tag_name' => 'domain',
            'domain' => 'header-auth.local',
        ]));

    $response->assertOk();
});

it('returns 403 when auth is enabled but configured token is null', function () {
    config([
        'freeswitch.xml_handler.auth' => true,
        'freeswitch.xml_handler.token' => null,
    ]);

    // Even a valid-looking request must be rejected when the server has no token configured
    $response = $this->withToken('anything')
        ->get('/api/v1/xml-handler?'.http_build_query([
            'section' => 'directory',
            'tag_name' => 'domain',
            'domain' => 'no-token.example.com',
        ]));

    $response->assertForbidden()
        ->assertSee('Access Denied');
});

it('returns 403 when auth is enabled but configured token is empty string', function () {
    config([
        'freeswitch.xml_handler.auth' => true,
        'freeswitch.xml_handler.token' => '',
    ]);

    $response = $this->withToken('anything')
        ->get('/api/v1/xml-handler?'.http_build_query([
            'section' => 'directory',
            'tag_name' => 'domain',
            'domain' => 'empty-token.example.com',
        ]));

    $response->assertForbidden()
        ->assertSee('Access Denied');
});

// ─── Controller Uses TenantManager ──────────────────────────────

it('injects TenantManager into the controller', function () {
    $controller = app(XmlHandlerController::class);

    expect($controller)->toBeInstanceOf(XmlHandlerController::class);
});

// ─── Tenant Resolution from SIP Domain ──────────────────────────

it('resolves tenant from SIP domain in directory request', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'sip.example.com',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'sip.example.com',
    ]));

    $response->assertOk();
    expect(app(TenantManager::class)->getTenantId())->toBe((string) $tenant->id);
});

it('resolves tenant from SIP domain in dialplan request', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'sip.example.com',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => 'public',
        'Caller-Destination-Number' => '1001',
        'Caller-Domain' => 'sip.example.com',
    ]));

    $response->assertOk();
    expect(app(TenantManager::class)->getTenantId())->toBe((string) $tenant->id);
});

it('clears tenant context when domain is unknown', function () {
    // Set a tenant context first
    app(TenantManager::class)->setTenantId('some-tenant-id');

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'unknown.domain.com',
    ]));

    $response->assertOk();
    expect(app(TenantManager::class)->getTenantId())->toBeNull();
});

it('clears tenant context when no domain is provided', function () {
    // Set a tenant context first
    app(TenantManager::class)->setTenantId('some-tenant-id');

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
    ]));

    $response->assertOk();
    expect(app(TenantManager::class)->getTenantId())->toBeNull();
});

it('does not resolve disabled tenant domains', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'disabled.example.com',
        'enabled' => false,
    ]);

    app(TenantManager::class)->setTenantId('some-tenant-id');

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'disabled.example.com',
    ]));

    $response->assertOk();
    // Context should be cleared since the domain is disabled
    expect(app(TenantManager::class)->getTenantId())->toBeNull();
});

// ─── 4.4: Real Directory Serving ────────────────────────────────

it('serves real domain directory with SIP accounts as users', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'sip.real-domain.com',
        'enabled' => true,
    ]);
    $account = SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'user5001',
        'user_context' => 'tenant_'.$tenant->id.'_internal',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'sip.real-domain.com',
        'key_value' => 'sip.real-domain.com',
    ]));

    $response->assertOk()
        ->assertSee('<domain name="sip.real-domain.com">', false)
        ->assertSee('user5001', false)
        ->assertSee('<param name="password"', false)
        ->assertSee('user_context', false)
        ->assertSee('tenant_id', false);
});

it('serves real user entry for known SIP account', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'user-entry.example.com',
        'enabled' => true,
    ]);
    $account = SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'user6001',
        'user_context' => 'custom_context',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'user',
        'domain' => 'user-entry.example.com',
        'key_value' => 'user6001',
        'sip_auth_username' => 'user6001',
    ]));

    $response->assertOk()
        ->assertSee('user6001', false)
        ->assertSee('<param name="password"', false)
        ->assertSee('custom_context', false)
        ->assertSee((string) $tenant->id, false)
        ->assertSee(
            'name="vm-domain-storage-dir" value="'.config('media-storage.store_root').'/runtime/'.$tenant->id.'/voicemail-message"',
            false,
        )
        // mod_voicemail (1.11+) resolves voicemail storage from the user's
        // <params> block; older builds read the variable form. Both must be
        // present so deposits land in the managed media root on every build.
        ->assertSee(
            '<param name="vm-domain-storage-dir" value="'.config('media-storage.store_root').'/runtime/'.$tenant->id.'/voicemail-message"',
            false,
        );
});

it('does not let log-only feature code markers shadow built-in feature dialplans', function () {
    $tenant = Tenant::factory()->create();
    app(TenantDefaultsService::class)->provision($tenant);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => 'tenant_'.$tenant->id.'_internal',
        'destination_number' => '*732',
    ]));

    $response->assertOk();
    $xml = $response->getContent();

    // The feature-code marker extension (log-only) is emitted before the
    // standard dialplans, and the tenant context is continue=false, so the
    // marker must itself continue the dialplan — otherwise the real *732
    // implementation (answer + record_session) never runs.
    expect($xml)->toContain('<extension name="feature_Call Record" continue="true">')
        ->and($xml)->toContain('recording-start')
        ->and(strpos($xml, '<extension name="recording-start">'))->not->toBeFalse()
        // Forced stereo makes record_session fail instantly on a single-leg
        // *732 call; the feature must record mono so the call stays active.
        ->and($xml)->not->toContain('RECORD_STEREO');
});

it('caches repeated directory user lookups when enabled', function () {
    config([
        'cache.default' => 'array',
        'freeswitch.xml_handler.directory_cache_store' => 'array',
        'freeswitch.xml_handler.directory_cache_ttl' => 60,
    ]);
    Cache::store('array')->flush();

    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'cached-directory.example.com',
        'enabled' => true,
    ]);
    $account = SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'cached_user',
        'auth_password' => 'old-secret',
        'enabled' => true,
    ]);

    $query = http_build_query([
        'section' => 'directory',
        'tag_name' => 'user',
        'domain' => 'cached-directory.example.com',
        'key_value' => 'cached_user',
        'sip_auth_username' => 'cached_user',
    ]);

    $this->get('/api/v1/xml-handler?'.$query)
        ->assertOk()
        ->assertSee('old-secret', false);

    $account->update(['auth_password' => 'new-secret']);

    $this->get('/api/v1/xml-handler?'.$query)
        ->assertOk()
        ->assertSee('old-secret', false)
        ->assertDontSee('new-secret', false);
});

it('returns not-found for unknown user in directory', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'unknown-user.example.com',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'user',
        'domain' => 'unknown-user.example.com',
        'sip_auth_username' => 'nonexistent_user',
    ]));

    $response->assertOk()
        ->assertSee('User not found: nonexistent_user', false);
});

it('excludes disabled accounts from directory domain listing', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'no-disabled.example.com',
        'enabled' => true,
    ]);
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'enabled_user',
        'enabled' => true,
    ]);
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'disabled_user',
        'enabled' => false,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'no-disabled.example.com',
    ]));

    $response->assertOk()
        ->assertSee('enabled_user', false)
        ->assertDontSee('disabled_user', false);
});

// ─── 4.4: Real Dialplan Serving ─────────────────────────────────

it('serves real dialplan rules for tenant context with matching dialplans', function () {
    $tenant = Tenant::factory()->create();
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    $dialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Internal Extensions',
        'context' => $context,
        'enabled' => true,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dialplan->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^(\d+)$',
        'action' => 'bridge',
        'data' => 'sofia/${domain_profile_id}/${destination_number}@${domain}',
        'order' => 10,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '1001',
        'Caller-Caller-ID-Number' => '2001',
    ]));

    $response->assertOk()
        ->assertSee('<context name="'.$context.'">', false)
        ->assertSee('Internal Extensions', false)
        ->assertSee('destination_number', false)
        ->assertSee('bridge', false)
        ->assertDontSee('NO_ROUTE_DESTINATION');
});

it('caches standard dialplan XML by tenant context across different destinations', function () {
    config([
        'cache.default' => 'array',
        'freeswitch.xml_handler.dialplan_cache_store' => 'array',
        'freeswitch.xml_handler.dialplan_cache_ttl' => 0,
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 60,
    ]);
    Cache::store('array')->flush();

    $tenant = Tenant::factory()->create();
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    $dialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Cached Standard Dialplan',
        'context' => $context,
        'enabled' => true,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dialplan->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^(\d+)$',
        'action' => 'bridge',
        'data' => 'user/${destination_number}',
        'order' => 10,
    ]);

    $dialplanQueries = 0;
    DB::listen(function ($query) use (&$dialplanQueries): void {
        if (str_contains($query->sql, 'from "dialplans"') || str_contains($query->sql, 'from `dialplans`')) {
            $dialplanQueries++;
        }
    });

    foreach (['1001', '1002'] as $destination) {
        $this->get('/api/v1/xml-handler?'.http_build_query([
            'section' => 'dialplan',
            'Caller-Context' => $context,
            'Caller-Destination-Number' => $destination,
            'Caller-Caller-ID-Number' => '2001',
        ]))
            ->assertOk()
            ->assertSee('Cached Standard Dialplan', false);
    }

    expect($dialplanQueries)->toBe(1);
});

it('returns default no-route when no dialplans match the tenant context', function () {
    $tenant = Tenant::factory()->create();
    $internalContext = app(DialplanContext::class)->internal((string) $tenant->id);

    // Create a dialplan for a different context
    Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Public Dialplan',
        'context' => app(DialplanContext::class)->public((string) $tenant->id),
        'enabled' => true,
    ]);

    // Request with internal context — should not match public dialplan
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $internalContext,
        'Caller-Destination-Number' => '1001',
    ]));

    $response->assertOk()
        ->assertSee('NO_ROUTE_DESTINATION');
});

it('returns default no-route for non-tenant context string', function () {
    $tenant = Tenant::factory()->create();
    Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'context' => app(DialplanContext::class)->internal((string) $tenant->id),
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => 'default',
        'Caller-Destination-Number' => '1001',
    ]));

    $response->assertOk()
        ->assertSee('NO_ROUTE_DESTINATION');
});

it('excludes disabled dialplans from tenant context response', function () {
    $tenant = Tenant::factory()->create();
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Enabled Plan',
        'context' => $context,
        'enabled' => true,
    ]);
    Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Disabled Plan',
        'context' => $context,
        'enabled' => false,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '1001',
    ]));

    $response->assertOk()
        ->assertSee('Enabled Plan', false)
        ->assertDontSee('Disabled Plan', false);
});

// ─── Shared-Domain Tenant Resolution ────────────────────────────

it('resolves tenant for directory request with shared domain and SIP auth username', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Two tenants share the same domain
    $domainA = TenantDomain::factory()->create([
        'tenant_id' => $tenantA->id,
        'domain' => 'shared.example.com',
        'enabled' => true,
    ]);
    TenantDomain::factory()->create([
        'tenant_id' => $tenantB->id,
        'domain' => 'shared.example.com',
        'enabled' => true,
    ]);

    // Each tenant has a unique SIP account on the shared domain
    SipAccount::factory()->domainUsername('alice', $domainA->id)->create([
        'tenant_id' => $tenantA->id,
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'shared.example.com',
        'sip_auth_username' => 'alice',
        'key_value' => 'shared.example.com',
        'sip_auth_realm' => 'shared.example.com',
    ]));

    $response->assertOk();

    // Should resolve to tenant A via domain+username match
    expect(app(TenantManager::class)->getTenantId())->toBe((string) $tenantA->id);
});

it('fails closed for directory request with shared domain but no auth username', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantDomain::factory()->create([
        'tenant_id' => $tenantA->id,
        'domain' => 'shared.example.com',
        'enabled' => true,
    ]);
    TenantDomain::factory()->create([
        'tenant_id' => $tenantB->id,
        'domain' => 'shared.example.com',
        'enabled' => true,
    ]);

    app(TenantManager::class)->setTenantId('some-previous-id');

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'shared.example.com',
        'key_value' => 'shared.example.com',
    ]));

    $response->assertOk();

    // Must fail closed — domain-only resolution is ambiguous when shared
    expect(app(TenantManager::class)->getTenantId())->toBeNull();
});

it('resolves dialplan from tenant context even with shared domain', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Both tenants share the same domain
    TenantDomain::factory()->create([
        'tenant_id' => $tenantA->id,
        'domain' => 'shared.example.com',
        'enabled' => true,
    ]);
    TenantDomain::factory()->create([
        'tenant_id' => $tenantB->id,
        'domain' => 'shared.example.com',
        'enabled' => true,
    ]);

    $context = app(DialplanContext::class)->internal((string) $tenantA->id);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '2001',
        'Caller-Domain' => 'shared.example.com',
    ]));

    $response->assertOk();

    // Dialplan context-based resolution takes priority over shared domain
    expect(app(TenantManager::class)->getTenantId())->toBe((string) $tenantA->id);
});

// ─── Phase 3: Contributors without base dialplans ───────────────

it('includes inbound route contributor XML even without a base dialplan record', function () {
    $tenant = Tenant::factory()->create();
    $context = app(DialplanContext::class)->public((string) $tenant->id);

    // Create an inbound route but NO base dialplan
    InboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'support_did',
        'destination_number' => '8005551212',
        'action' => 'transfer',
        'action_data' => '2000 XML default',
        'priority' => 1,
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '8005551212',
    ]));

    $response->assertOk()
        ->assertSee('support_did', false)
        ->assertSee('8005551212', false)
        ->assertDontSee('NO_ROUTE_DESTINATION');
});

it('includes ring group contributor XML even without a base dialplan record', function () {
    $tenant = Tenant::factory()->create();
    $context = app(DialplanContext::class)->public((string) $tenant->id);

    // Create a ring group but NO base dialplan
    RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'support_team',
        'strategy' => 'ring-all',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '3000',
    ]));

    $response->assertOk()
        ->assertSee('support_team', false)
        ->assertDontSee('NO_ROUTE_DESTINATION');
});

it('returns default no-route when no dialplans and no contributors have matching rules', function () {
    $tenant = Tenant::factory()->create();
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    // No dialplans, no contributors with matching rules — should get no-route
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '9999',
    ]));

    $response->assertOk()
        ->assertSee('NO_ROUTE_DESTINATION');
});

it('coexists base dialplans and contributor XML in the same context', function () {
    $tenant = Tenant::factory()->create();
    $context = app(DialplanContext::class)->public((string) $tenant->id);

    // Both a base dialplan AND a feature contributor
    $dp = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'direct_extension',
        'context' => $context,
        'order' => 0,
        'enabled' => true,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dp->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^100$',
        'action' => 'bridge',
        'data' => 'user/100',
    ]);

    InboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'sales',
        'destination_number' => '8005551212',
        'action' => 'transfer',
        'action_data' => 'sales_queue',
        'priority' => 10,
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '8005551212',
    ]));

    $response->assertOk()
        ->assertSee('direct_extension', false)
        ->assertSee('sales', false)
        ->assertSee('sales_queue', false)
        ->assertDontSee('NO_ROUTE_DESTINATION');
});

it('serves a dynamic acl.conf with the domains list and tenant domain nodes', function (): void {
    Cache::forget('freeswitch:acl');
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'enabled' => true]);
    TenantDomain::create([
        'tenant_id' => $tenant->id,
        'domain' => 'pbx.acme.example',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'acl.conf',
    ]));

    $response->assertOk();
    expect($response->getContent())
        ->toContain('<configuration name="acl.conf" description="Network Lists">')
        ->toContain('<list name="domains" default="deny">')
        ->toContain('<node type="allow" domain="$${domain}"/>')
        ->toContain('<node type="allow" domain="pbx.acme.example"/>');
});

it('renders enabled access control rules as tenant-prefixed lists', function (): void {
    Cache::forget('freeswitch:acl');
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'enabled' => true]);
    $rule = AccessControl::create([
        'tenant_id' => $tenant->id,
        'name' => 'trunk-provider',
        'action' => 'allow',
        'enabled' => true,
    ]);
    $rule->nodes()->create(['type' => 'cidr', 'value' => '10.0.0.0/8', 'order' => 1]);
    $rule->nodes()->create(['type' => 'domain', 'value' => 'sip.provider.example', 'order' => 2]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'acl.conf',
    ]));

    $response->assertOk();
    expect($response->getContent())
        ->toContain('<list name="acme.trunk-provider" default="allow">')
        ->toContain('<node type="allow" cidr="10.0.0.0/8"/>')
        ->toContain('<node type="allow" domain="sip.provider.example"/>');
});

it('omits disabled rules and empty lists from acl.conf', function (): void {
    Cache::forget('freeswitch:acl');
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'enabled' => true]);
    $disabled = AccessControl::create([
        'tenant_id' => $tenant->id,
        'name' => 'disabled-rule',
        'action' => 'deny',
        'enabled' => false,
    ]);
    $disabled->nodes()->create(['type' => 'cidr', 'value' => '192.168.1.0/24']);
    AccessControl::create([
        'tenant_id' => $tenant->id,
        'name' => 'empty-rule',
        'action' => 'deny',
        'enabled' => true,
    ]);

    $content = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'acl.conf',
    ]))->getContent();

    expect($content)
        ->not->toContain('disabled-rule')
        ->not->toContain('empty-rule');
});

it('prefixes same-named rules from different tenants to avoid list collisions', function (): void {
    Cache::forget('freeswitch:acl');
    $first = Tenant::factory()->create(['slug' => 'acme', 'enabled' => true]);
    $second = Tenant::factory()->create(['slug' => 'globex', 'enabled' => true]);
    foreach ([$first, $second] as $tenant) {
        $rule = AccessControl::create([
            'tenant_id' => $tenant->id,
            'name' => 'trunk-provider',
            'action' => 'allow',
            'enabled' => true,
        ]);
        $rule->nodes()->create(['type' => 'cidr', 'value' => '10.0.0.0/8']);
    }

    $content = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'acl.conf',
    ]))->getContent();

    expect($content)
        ->toContain('<list name="acme.trunk-provider"')
        ->toContain('<list name="globex.trunk-provider"')
        ->not->toContain('<list name="trunk-provider"');
});

it('escapes hostile rule names and node values in acl.conf', function (): void {
    Cache::forget('freeswitch:acl');
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'enabled' => true]);
    $rule = AccessControl::create([
        'tenant_id' => $tenant->id,
        'name' => 'weird & "rule"',
        'action' => 'allow',
        'enabled' => true,
    ]);
    $rule->nodes()->create(['type' => 'cidr', 'value' => '10.0.0.0/8" onmouseover="x']);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'acl.conf',
    ]));

    $response->assertOk();
    // The raw attribute-injection form must be gone; the word itself can
    // survive escaped output (only the quotes are encoded).
    expect($response->getContent())
        ->not->toContain('" onmouseover=')
        ->toContain('&amp;')
        ->toContain('&quot;');
});

it('caches the generated acl.conf document', function (): void {
    Cache::forget('freeswitch:acl');

    $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'acl.conf',
    ]))->assertOk();

    expect(Cache::has('freeswitch:acl'))->toBeTrue();
});

it('serves the PIN prompt phrase macros for section phrases', function () {
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'phrases',
    ]));

    $response->assertOk()
        ->assertSee('<section name="phrases">', false)
        ->assertSee('<macro name="pin_number_enter">', false)
        ->assertSee('<macro name="pin_number_destination">', false)
        // FreeSWITCH's phrase engine reads the function attribute.
        ->assertSee('function="play-file"', false)
        ->assertSee('tone_stream://%(1000,0,640)', false);
});

it('serves all PIN macros regardless of the requested key', function () {
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'phrases',
        'key' => 'pin_number_enter',
    ]));

    // The phrase engine looks macros up by name, so the single-purpose
    // section returns all PIN macros no matter which key was requested.
    $response->assertOk()
        ->assertSee('<macro name="pin_number_enter">', false)
        ->assertSee('<macro name="pin_number_destination">', false);
});

// ─── Extension Settings overrides ───────────────────────────────

it('exports allowlisted extension settings as directory variables', function () {
    $tenant = Tenant::factory()->create();
    $extension = Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'display_name' => 'Jane Doe',
    ]);
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_id' => $extension->id,
        'auth_username' => 'auth_user',
        'identity_mode' => 'global_username',
        'enabled' => true,
    ]);
    ExtensionSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_id' => $extension->id,
        'key' => 'effective_caller_id_name',
        'value' => 'Ops Desk',
    ]);
    ExtensionSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_id' => $extension->id,
        'key' => 'not_allowlisted',
        'value' => 'HACK',
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'user',
        'key_value' => 'auth_user',
        'sip_auth_username' => 'auth_user',
    ]));

    $response->assertOk()
        ->assertSee('<variable name="effective_caller_id_name" value="Ops Desk"/>', false)
        ->assertDontSee('not_allowlisted')
        ->assertDontSee('HACK');
});

it('does not export extension settings from another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $extensionA = Extension::factory()->create(['tenant_id' => $tenantA->id]);
    SipAccount::factory()->create([
        'tenant_id' => $tenantA->id,
        'extension_id' => $extensionA->id,
        'auth_username' => 'auth_user',
        'identity_mode' => 'global_username',
        'enabled' => true,
    ]);

    $extensionB = Extension::factory()->create(['tenant_id' => $tenantB->id]);
    ExtensionSetting::factory()->create([
        'tenant_id' => $tenantB->id,
        'extension_id' => $extensionB->id,
        'key' => 'effective_caller_id_name',
        'value' => 'Leaked Name',
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'user',
        'key_value' => 'auth_user',
        'sip_auth_username' => 'auth_user',
    ]));

    $response->assertOk()
        ->assertDontSee('Leaked Name');
});

it('exports caller id variables directly from extension model in directory xml', function () {
    $tenant = Tenant::factory()->create();
    $extension = Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '1001',
        'effective_caller_id_name' => 'John Doe',
        'effective_caller_id_number' => '1001',
        'outbound_caller_id_name' => 'Acme Corp',
        'outbound_caller_id_number' => '15551234567',
    ]);
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_id' => $extension->id,
        'auth_username' => '1001',
        'identity_mode' => 'global_username',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'user',
        'key_value' => '1001',
        'sip_auth_username' => '1001',
    ]));

    $response->assertOk()
        ->assertSee('<variable name="effective_caller_id_name" value="John Doe"/>', false)
        ->assertSee('<variable name="effective_caller_id_number" value="1001"/>', false)
        ->assertSee('<variable name="outbound_caller_id_name" value="Acme Corp"/>', false)
        ->assertSee('<variable name="outbound_caller_id_number" value="15551234567"/>', false);
});
