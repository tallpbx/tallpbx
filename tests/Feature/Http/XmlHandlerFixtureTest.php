<?php

declare(strict_types=1);

/**
 * mod_xml_curl fixture tests — validate XML responses against realistic
 * FreeSWITCH request parameter sets.
 *
 * These tests simulate actual FreeSWITCH mod_xml_curl HTTP requests
 * with the complete set of query parameters that FreeSWITCH sends.
 * They validate that the XML handler produces well-formed, correct
 * FreeSWITCH-compatible XML for each request type.
 *
 * The tests are organized by FreeSWITCH request flow:
 *   1. SIP Registration (directory section)
 *   2. Call Routing (dialplan section)
 *   3. Configuration Loading (configuration section)
 *   4. Tenant Isolation & Multi-tenancy
 *   5. Error Handling & Edge Cases
 */

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\DialplanContext;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Modules\CallBlocks\Models\CallBlock;
use Modules\CallCenters\Models\Queue;
use Modules\CallForwards\Models\CallForward;
use Modules\Conferences\Models\Conference;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Models\DialplanDetail;
use Modules\Emergency\Models\Emergency;
use Modules\FeatureCodes\Models\FeatureCode;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\IvrMenus\Models\IvrMenuOption;
use Modules\RingGroups\Models\RingGroup;
use Modules\SipAccounts\Models\SipAccount;
use Modules\SipProfiles\Models\SipProfile;
use Modules\TimeConditions\Models\TimeCondition;
use Modules\Voicemails\Models\Voicemail;

beforeEach(function () {
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.dialplan_cache_store' => 'array',
        'freeswitch.xml_handler.dialplan_cache_ttl' => 0,
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 0,
    ]);
    Cache::store('array')->flush();
    app(TenantManager::class)->clear();
});

// ─── Helpers ─────────────────────────────────────────────────────

/**
 * Build a realistic FreeSWITCH mod_xml_curl query string for
 * directory requests. Includes parameters FreeSWITCH sends
 * during SIP registration and endpoint lookups.
 *
 * @param  array<string, string>  $overrides  Key-value overrides for specific test scenarios
 * @return string URL-encoded query string
 */
function fsDirectoryQuery(array $overrides = []): string
{
    $params = array_merge([
        'section' => 'directory',
        'tag_name' => 'domain',
        'key_name' => 'name',
        'key_value' => 'example.com',
        'domain' => 'example.com',
        'sip_profile' => 'internal',
        'FreeSWITCH-Hostname' => 'fs01.local',
        'Event-Calling-Function' => 'sofia_reg_parse_auth',
        'hostname' => 'fs01.local',
        'action' => 'sip_auth',
        'user' => '1001',
        'sip_auth_username' => '1001',
        'sip_auth_realm' => 'example.com',
        'sip_user_agent' => 'Yealink SIP-T46G 28.83.0.20',
        'sip_contact_user' => '1001',
        'sip_contact_host' => '192.168.1.50',
        'ip' => '192.168.1.50',
    ], $overrides);

    return http_build_query($params);
}

/**
 * Build a realistic FreeSWITCH mod_xml_curl query string for
 * dialplan requests. Includes all parameters FreeSWITCH sends
 * during call routing lookup.
 *
 * @param  array<string, string>  $overrides  Key-value overrides
 * @return string URL-encoded query string
 */
function fsDialplanQuery(array $overrides = []): string
{
    $params = array_merge([
        'section' => 'dialplan',
        'Caller-Context' => 'public',
        'Caller-Destination-Number' => '1001',
        'Caller-Caller-ID-Number' => '2001',
        'Caller-Caller-ID-Name' => 'John Doe',
        'Caller-Domain' => 'example.com',
        'Caller-ANI' => '2001',
        'Caller-DNIS' => '1001',
        'Caller-Channel-Name' => 'sofia/internal/2001@example.com',
        'Caller-Profile-Index' => '1',
        'FreeSWITCH-Hostname' => 'fs01.local',
        'Event-Calling-Function' => 'switch_ivr_find_exten',
        'hostname' => 'fs01.local',
        'sip_profile' => 'internal',
    ], $overrides);

    return http_build_query($params);
}

/**
 * Build a realistic FreeSWITCH mod_xml_curl query string for
 * configuration requests. Used when FreeSWITCH loads/reloads
 * module configurations via mod_xml_curl.
 *
 * @param  array<string, string>  $overrides  Key-value overrides
 * @return string URL-encoded query string
 */
function fsConfigurationQuery(array $overrides = []): string
{
    $params = array_merge([
        'section' => 'configuration',
        'key_name' => 'name',
        'key_value' => 'switch.conf',
        'FreeSWITCH-Hostname' => 'fs01.local',
        'Event-Calling-Function' => 'switch_load_core_config',
        'hostname' => 'fs01.local',
    ], $overrides);

    return http_build_query($params);
}

/**
 * Assert that a response body is valid XML and contains the
 * required FreeSWITCH document structure.
 */
function assertValidFreeswitchXml(string $xml): void
{
    // Must start with XML declaration
    expect($xml)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');

    // Must contain the freeswitch/xml document type
    expect($xml)->toContain('<document type="freeswitch/xml">');

    // Must be well-formed — close the document tag (with or without trailing newline)
    expect(rtrim($xml))->toEndWith('</document>');
}

/**
 * Create a test tenant with a SIP domain and return both.
 *
 * @return array{tenant: Tenant, domain: TenantDomain}
 */
function createTenantWithDomain(string $domain = 'sip.example.com'): array
{
    $tenant = Tenant::factory()->create();
    $tenantDomain = TenantDomain::factory()->for($tenant)->sipRealm()->create([
        'domain' => $domain,
        'enabled' => true,
    ]);

    return ['tenant' => $tenant, 'domain' => $tenantDomain];
}

// ═══════════════════════════════════════════════════════════════════
//  1. SIP REGISTRATION FLOW (Directory Section)
// ═══════════════════════════════════════════════════════════════════

it('handles a complete SIP registration domain lookup with real FreeSWITCH parameters', function () {
    extract(createTenantWithDomain('sip.pbx.local'));

    // Create SIP accounts that would register
    SipAccount::factory()->forTenant($tenant->id)->create([
        'tenant_domain_id' => $domain->id,
        'auth_username' => '2001',
        'auth_password' => 'hashed_secret_1',
        'user_context' => 'tenant_'.$tenant->id.'_internal',
        'enabled' => true,
    ]);
    SipAccount::factory()->forTenant($tenant->id)->create([
        'tenant_domain_id' => $domain->id,
        'auth_username' => '2002',
        'auth_password' => 'hashed_secret_2',
        'user_context' => 'tenant_'.$tenant->id.'_internal',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDirectoryQuery([
        'domain' => 'sip.pbx.local',
        'key_value' => 'sip.pbx.local',
        'tag_name' => 'domain',
    ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    // Root document structure
    expect($xml)->toContain('<section name="directory">');
    expect($xml)->toContain('<domain name="sip.pbx.local">');

    // Domain params with dial-string (required by FreeSWITCH for SIP routing)
    expect($xml)->toContain('<param name="dial-string"');

    // Group structure
    expect($xml)->toContain('<group name="default">');
    expect($xml)->toContain('<users>');

    // Both SIP accounts must appear as user entries
    expect($xml)->toContain('<user id="2001">');
    expect($xml)->toContain('<user id="2002">');

    // Each user must have password and context variables
    expect($xml)->toContain('<variable name="user_context"');
    expect($xml)->toContain('<variable name="tenant_id"');
});

it('handles a SIP user auth lookup with the complete FreeSWITCH parameter set', function () {
    extract(createTenantWithDomain('auth.pbx.local'));

    SipAccount::factory()->forTenant($tenant->id)->create([
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'voip_user_42',
        'auth_password' => 'secure_passphrase',
        'user_context' => 'tenant_'.$tenant->id.'_internal',
        'enabled' => true,
    ]);

    // FreeSWITCH sends sip_auth_username and sip_auth_realm during SIP REGISTER
    $response = $this->get('/api/v1/xml-handler?'.fsDirectoryQuery([
        'domain' => 'auth.pbx.local',
        'tag_name' => 'user',
        'key_value' => 'voip_user_42',
        'sip_auth_username' => 'voip_user_42',
        'sip_auth_realm' => 'auth.pbx.local',
        'user' => 'voip_user_42',
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    // The user entry must be directly in the section (not wrapped in domain/group)
    expect($xml)->toContain('<section name="directory">');
    expect($xml)->toContain('<user id="voip_user_42">');
    expect($xml)->toContain('<param name="password"');

    // Variables must include user_context so FreeSWITCH routes calls correctly
    expect($xml)->toContain('tenant_'.$tenant->id.'_internal');
    expect($xml)->toContain((string) $tenant->id);
});

it('returns user not-found for SIP auth with unknown username', function () {
    extract(createTenantWithDomain('missing.pbx.local'));

    $response = $this->get('/api/v1/xml-handler?'.fsDirectoryQuery([
        'domain' => 'missing.pbx.local',
        'tag_name' => 'user',
        'sip_auth_username' => 'ghost_user',
        'sip_auth_realm' => 'missing.pbx.local',
        'user' => 'ghost_user',
    ]));

    $response->assertOk(); // FreeSWITCH expects 200 even for not-found
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('User not found: ghost_user');
    expect($xml)->not->toContain('<param name="password"');
});

it('excludes disabled SIP accounts from domain directory but not from user lookup', function () {
    extract(createTenantWithDomain('disabled-test.pbx'));

    SipAccount::factory()->forTenant($tenant->id)->create([
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'active_user',
        'enabled' => true,
    ]);
    SipAccount::factory()->forTenant($tenant->id)->create([
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'disabled_user',
        'enabled' => false,
    ]);

    // Domain lookup — disabled user must not appear
    $domainResponse = $this->get('/api/v1/xml-handler?'.fsDirectoryQuery([
        'domain' => 'disabled-test.pbx',
        'key_value' => 'disabled-test.pbx',
        'tag_name' => 'domain',
    ]));

    $domainResponse->assertOk();
    expect($domainResponse->content())->toContain('active_user');
    expect($domainResponse->content())->not->toContain('disabled_user');

    // User lookup for disabled account — should not find it
    $userResponse = $this->get('/api/v1/xml-handler?'.fsDirectoryQuery([
        'domain' => 'disabled-test.pbx',
        'tag_name' => 'user',
        'sip_auth_username' => 'disabled_user',
        'user' => 'disabled_user',
    ]));

    $userResponse->assertOk();
    expect($userResponse->content())->toContain('User not found: disabled_user');
});

// ═══════════════════════════════════════════════════════════════════
//  2. CALL ROUTING FLOW (Dialplan Section)
// ═══════════════════════════════════════════════════════════════════

it('routes an internal extension call with complete FreeSWITCH dialplan parameters', function () {
    extract(createTenantWithDomain('routing.pbx.local'));

    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    $dialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Extension-to-Extension',
        'context' => $context,
        'enabled' => true,
        'order' => 10,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dialplan->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^(\d{3,6})$',
        'action' => 'bridge',
        'data' => 'user/${destination_number}@${domain}',
        'order' => 10,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dialplan->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^(\d{3,6})$',
        'action' => 'export',
        'data' => 'origination_callee_id_name=${destination_number}',
        'order' => 20,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '2001',
        'Caller-Caller-ID-Number' => '1000',
        'Caller-Domain' => 'routing.pbx.local',
        'Caller-Channel-Name' => 'sofia/internal/1000@routing.pbx.local',
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    // Dialplan section with context
    expect($xml)->toContain('<section name="dialplan">');
    expect($xml)->toContain('<context name="'.$context.'">');

    // Extension with condition and actions
    expect($xml)->toContain('<extension name="Extension-to-Extension">');
    expect($xml)->toContain('<condition field="destination_number" expression="^(\\d{3,6})$">');
    expect($xml)->toContain('<action application="bridge"');
    expect($xml)->toContain('<action application="export"');

    // Must not fall back to no-route
    expect($xml)->not->toContain('NO_ROUTE_DESTINATION');
});

it('routes an inbound DID call through public context', function () {
    extract(createTenantWithDomain('inbound-test.pbx'));

    $publicContext = app(DialplanContext::class)->public((string) $tenant->id);

    $dialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Inbound DID Handler',
        'context' => $publicContext,
        'enabled' => true,
        'order' => 5,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dialplan->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^15551234567$',
        'action' => 'transfer',
        'data' => '2001 XML default',
        'order' => 10,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $publicContext,
        'Caller-Destination-Number' => '15551234567',
        'Caller-Caller-ID-Number' => '+12125551000',
        'Caller-Caller-ID-Name' => 'External Caller',
        'Caller-Domain' => 'inbound-test.pbx',
        'Caller-Channel-Name' => 'sofia/external/+12125551000@carrier.example.com',
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('Inbound DID Handler');
    expect($xml)->toContain('transfer');
    expect($xml)->toContain('2001 XML default');
});

it('honors dialplan ordering when multiple dialplans match a context', function () {
    extract(createTenantWithDomain('ordered.pbx'));

    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    // Create dialplans with different order values
    $firstDialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'First Match',
        'context' => $context,
        'enabled' => true,
        'order' => 10,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $firstDialplan->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^(.*)$',
        'action' => 'set',
        'data' => 'first_match=true',
        'order' => 10,
    ]);

    $secondDialplan = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Second Match',
        'context' => $context,
        'enabled' => true,
        'order' => 20,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $secondDialplan->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^(.*)$',
        'action' => 'set',
        'data' => 'second_match=true',
        'order' => 10,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '9999',
    ]));

    $response->assertOk();
    $xml = $response->content();

    // Both dialplans must appear; FreeSWITCH evaluates them in order
    expect($xml)->toContain('First Match');
    expect($xml)->toContain('Second Match');

    // Check that "First Match" appears before "Second Match" in the XML
    $firstPos = strpos($xml, 'First Match');
    $secondPos = strpos($xml, 'Second Match');
    expect($firstPos)->toBeLessThan($secondPos);
});

it('places original caller capture and call blocks before the local extension catch-all', function () {
    extract(createTenantWithDomain('ordering-regression.pbx'));
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    CallBlock::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'blocked_caller',
        'caller_id_number' => '^5550100$',
        'enabled' => true,
    ]);

    $localExtension = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'local_extension',
        'context' => $context,
        'enabled' => true,
        'order' => 30,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $localExtension->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^([1-9][0-9]{2,5})$',
        'action' => 'bridge',
        'data' => 'user/$1@${domain_name}',
        'order' => 10,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '2001',
        'Caller-Caller-ID-Number' => '5550100',
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)
        ->toContain('orig_caller_id_number=${caller_id_number}')
        ->toContain('<condition field="orig_caller_id_number" expression="^5550100$">');

    $capturePosition = strpos($xml, 'capture_orig_caller');
    $blockPosition = strpos($xml, 'block_blocked_caller');
    $localExtensionPosition = strpos($xml, '<extension name="local_extension">');

    expect($capturePosition)->toBeInt()
        ->and($blockPosition)->toBeInt()
        ->and($localExtensionPosition)->toBeInt()
        ->and($capturePosition)->toBeLessThan($blockPosition)
        ->and($blockPosition)->toBeLessThan($localExtensionPosition);
});

it('falls back to default no-route when no context matches', function () {
    // Omit Caller-Domain so the handler cannot resolve a tenant via domain fallback.
    // The unknown context forces a no-route response.
    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => 'nonexistent_context',
        'Caller-Destination-Number' => '5551212',
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    // Must return a hangup action so the call terminates cleanly
    expect($xml)->toContain('NO_ROUTE_DESTINATION');
    expect($xml)->toContain('<action application="hangup"');
});

// ─── P3: Feature Module Dialplan Contributions ────────────────────

it('includes inbound route XML in the public dialplan context', function () {
    extract(createTenantWithDomain('pstn.example.com'));
    $context = app(DialplanContext::class)->public((string) $tenant->id);

    // Create an inbound route that should appear in the public context
    InboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'sales_did',
        'destination_number' => '8005551212',
        'action' => 'transfer',
        'action_data' => '100 XML default',
        'priority' => 1,
        'enabled' => true,
    ]);

    // Also create a dialplan so the context is built (contributor XML is
    // included only when at least one dialplan exists for the context)
    $dp = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'base_context',
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

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '8005551212',
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    // The contextual dialplan and inbound route must both be present
    expect($xml)->toContain('base_context');
    expect($xml)->toContain('inbound_sales_did');
    expect($xml)->toContain('destination_number');
    expect($xml)->toContain('8005551212');
});

it('includes ring group XML with bridge destinations', function () {
    extract(createTenantWithDomain('rg.example.com'));
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    $dp = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'rg_context',
        'context' => $context,
        'order' => 0,
        'enabled' => true,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dp->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^.*$',
        'action' => 'log',
        'data' => 'INFO ring group hit',
    ]);

    RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'support_team',
        'strategy' => 'simultaneous',
        'ring_timeout' => 20,
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => 'support_team',
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('ring_group_support_team');
    expect($xml)->toContain('Feature module dialplan contributions');
});

it('includes IVR menu XML with bind_digit_action', function () {
    extract(createTenantWithDomain('ivr.example.com'));
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    $dp = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'ivr_context',
        'context' => $context,
        'order' => 0,
        'enabled' => true,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dp->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^.*$',
        'action' => 'log',
        'data' => 'INFO ivr hit',
    ]);

    IvrMenu::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'main_menu',
        'greeting' => 'welcome.wav',
        'timeout' => 3,
        'max_failures' => 3,
        'digit_length' => 1,
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => 'main_menu',
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('ivr_main_menu');
    expect($xml)->toContain('playback');
    expect($xml)->toContain('welcome.wav');
});

it('includes voicemail XML with the voicemail application', function () {
    extract(createTenantWithDomain('vm.example.com'));
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    $dp = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'vm_context',
        'context' => $context,
        'order' => 0,
        'enabled' => true,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dp->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^.*$',
        'action' => 'log',
        'data' => 'INFO vm hit',
    ]);

    Voicemail::factory()->create([
        'tenant_id' => $tenant->id,
        'voicemail_id' => '200',
        'mailbox' => '200',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '200',
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('voicemail_200');
    expect($xml)->toContain('application="voicemail"');
});

it('includes conference XML with conference bridge', function () {
    extract(createTenantWithDomain('conf.example.com'));
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    $dp = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'conf_context',
        'context' => $context,
        'order' => 0,
        'enabled' => true,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dp->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^.*$',
        'action' => 'log',
        'data' => 'INFO conf hit',
    ]);

    Conference::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'boardroom',
        'profile' => 'default',
        'max_members' => 50,
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => 'boardroom',
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('conference_boardroom');
    expect($xml)->toContain('application="conference"');
});

it('includes call center queue XML', function () {
    extract(createTenantWithDomain('cc.example.com'));
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    $dp = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'cc_context',
        'context' => $context,
        'order' => 0,
        'enabled' => true,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dp->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^.*$',
        'action' => 'log',
        'data' => 'INFO cc hit',
    ]);

    Queue::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'customer_support',
        'strategy' => 'longest-idle-agent',
        'timeout' => 30,
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => 'customer_support',
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('callcenter_customer_support');
    expect($xml)->toContain('application="callcenter"');
});

it('excludes disabled feature modules from dialplan XML', function () {
    extract(createTenantWithDomain('disabled.example.com'));
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    $dp = Dialplan::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'enabled_check',
        'context' => $context,
        'order' => 0,
        'enabled' => true,
    ]);
    DialplanDetail::factory()->create([
        'dialplan_id' => $dp->id,
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^.*$',
        'action' => 'log',
        'data' => 'INFO',
    ]);

    // Create a disabled ring group — must not appear in the dialplan
    RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'disabled_group',
        'strategy' => 'simultaneous',
        'ring_timeout' => 20,
        'enabled' => false,
    ]);

    // Create an enabled ring group — must appear
    RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'active_group',
        'strategy' => 'simultaneous',
        'ring_timeout' => 20,
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => 'active_group',
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();

    expect($xml)->toContain('ring_group_active_group');
    expect($xml)->not->toContain('disabled_group');
});

// ═══════════════════════════════════════════════════════════════════
//  3. CONFIGURATION LOADING (Configuration Section)
// ═══════════════════════════════════════════════════════════════════

it('serves switch.conf with complete FreeSWITCH startup parameters', function () {
    config([
        'freeswitch.switch.loglevel' => 'debug',
        'freeswitch.database.driver' => 'mariadb',
        'freeswitch.database.host' => 'db.internal',
        'freeswitch.database.port' => 3306,
        'freeswitch.database.database' => 'freeswitch',
        'freeswitch.database.username' => 'fs_user',
        'freeswitch.database.password' => 'db_secret',
        'freeswitch.database.auto_create_schemas' => true,
        'freeswitch.database.core_non_sqlite_db_required' => true,
        'freeswitch.database.auto_clear_sql' => true,
        'freeswitch.database.odbc_skip_autocommit_flip' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
        'key_value' => 'switch.conf',
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('<section name="configuration">');
    expect($xml)->toContain('<configuration name="switch.conf" description="Core Configuration">');
    expect($xml)->toContain('<settings>');
    expect($xml)->toContain('<param name="loglevel" value="debug"/>');
    expect($xml)->toContain('<param name="sessions-per-second" value="60"/>');

    // Core database configuration
    expect($xml)->toContain('core-db-dsn');
    expect($xml)->toContain('mariadb://Server=db.internal;Port=3306;Database=freeswitch;Uid=fs_user;Pwd=db_secret;');

    // Boolean params must use FreeSWITCH-compatible true/false strings
    expect($xml)->toContain('<param name="auto-create-schemas" value="true"/>');
    expect($xml)->toContain('<param name="core-non-sqlite-db-required" value="true"/>');
    expect($xml)->toContain('<param name="auto-clear-sql" value="true"/>');
    expect($xml)->toContain('<param name="odbc-skip-autocommit-flip" value="true"/>');
});

it('serves sofia.conf with tenant SIP profiles and global settings using real parameters', function () {
    config([
        'freeswitch.sofia.log_level' => 1,
        'freeswitch.sofia.auto_restart' => true,
        'freeswitch.sofia.debug_presence' => false,
        'freeswitch.sofia.capture_server' => 'udp:127.0.0.1:9060',
        'freeswitch.sofia.inbound_reg_in_new_thread' => false,
        'freeswitch.sofia.max_reg_threads' => 4,
    ]);

    extract(createTenantWithDomain('sofia-test.pbx'));

    SipProfile::factory()->forTenant($tenant->id)->create([
        'name' => 'internal',
        'settings' => [
            'sip-port' => '5060',
            'sip-ip' => '$${local_ip_v4}',
            'rtp-ip' => '$${local_ip_v4}',
            'ext-rtp-ip' => 'autonat:$${external_rtp_ip}',
            'ext-sip-ip' => 'autonat:$${external_sip_ip}',
            'dtmf-type' => 'rfc2833',
            'inbound-codec-prefs' => 'PCMU,PCMA,G722',
            'outbound-codec-prefs' => 'PCMU,PCMA',
            'context' => 'public',
            'dialplan' => 'XML',
            'manage-presence' => true,
            'rtp-timeout-sec' => 300,
        ],
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
        'key_value' => 'sofia.conf',
        'domain' => 'sofia-test.pbx',
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    // Global settings section
    expect($xml)->toContain('<global_settings>');
    expect($xml)->toContain('<param name="log-level" value="1"/>');
    expect($xml)->toContain('<param name="auto-restart" value="true"/>');
    expect($xml)->toContain('<param name="debug-presence" value="false"/>');
    expect($xml)->toContain('<param name="capture-server" value="udp:127.0.0.1:9060"/>');
    expect($xml)->toContain('<param name="inbound-reg-in-new-thread" value="false"/>');
    expect($xml)->toContain('<param name="max-reg-threads" value="4"/>');

    // Profiles section
    expect($xml)->toContain('<profiles>');
    expect($xml)->toContain('<profile name="internal">');
    expect($xml)->toContain('<param name="sip-port" value="5060"/>');
    expect($xml)->toContain('<param name="dtmf-type" value="rfc2833"/>');
    expect($xml)->toContain('<param name="inbound-codec-prefs" value="PCMU,PCMA,G722"/>');
    // Note: SipProfileService casts booleans via (string), so true → "1".
    // A future hardening step should normalize booleans to FreeSWITCH-compatible "true"/"false".
    expect($xml)->toContain('<param name="manage-presence" value="1"/>');
    expect($xml)->toContain('<param name="rtp-timeout-sec" value="300"/>');
});

it('serves sofia.conf without profiles when no tenant domain is resolved', function () {
    config(['freeswitch.sofia.log_level' => 0]);

    $response = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
        'key_value' => 'sofia.conf',
        'domain' => 'nonexistent.example.com',
    ]));

    $response->assertOk();
    $xml = $response->content();

    // Global settings should still be present
    expect($xml)->toContain('<global_settings>');
    expect($xml)->toContain('<profiles>');

    // Should indicate no tenant was resolved for profiles
    expect($xml)->toContain('No tenant domain resolved for Sofia profiles');
    expect($xml)->not->toContain('<profile name=');
});

it('returns placeholder XML for intentionally deferred FreeSWITCH configuration files', function () {
    // These configs are intentionally deferred — they have no module data
    // to generate real XML. The handler returns valid placeholder XML so
    // FreeSWITCH can fall back to static config or defaults. acl.conf is no
    // longer deferred: it is generated dynamically from access controls.
    foreach (['modules.conf', 'post_load_modules.conf', 'cdr_csv.conf'] as $configName) {
        $response = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
            'key_value' => $configName,
        ]));

        $response->assertOk();
        $xml = $response->content();
        assertValidFreeswitchXml($xml);

        expect($xml)->toContain('<section name="configuration">');
        expect($xml)->toContain($configName);

        // Placeholder must not contain actual config params — just a comment
        expect($xml)->not->toContain('<param name=');
        expect($xml)->not->toContain('<settings>');
    }
});

it('serves db.conf and fifo.conf configuration with database DSN', function () {
    config([
        'freeswitch.database.driver' => 'pgsql',
        'freeswitch.database.host' => 'pg-cluster.internal',
        'freeswitch.database.port' => 5432,
        'freeswitch.database.database' => 'freeswitch',
        'freeswitch.database.username' => 'fs_pg_user',
        'freeswitch.database.password' => 'pg_secret',
        'freeswitch.database.options' => 'sslmode=require',
    ]);

    // db.conf — LIMIT DB module
    $dbResponse = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
        'key_value' => 'db.conf',
    ]));

    $dbResponse->assertOk();
    $dbXml = $dbResponse->content();
    assertValidFreeswitchXml($dbXml);

    expect($dbXml)->toContain('<configuration name="db.conf" description="LIMIT DB Configuration">');
    expect($dbXml)->toContain('odbc-dsn');
    expect($dbXml)->toContain('pgsql://hostaddr=pg-cluster.internal');
    expect($dbXml)->toContain('sslmode=require');

    // fifo.conf — Call center FIFO module
    $fifoResponse = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
        'key_value' => 'fifo.conf',
    ]));

    $fifoResponse->assertOk();
    $fifoXml = $fifoResponse->content();
    assertValidFreeswitchXml($fifoXml);

    expect($fifoXml)->toContain('<configuration name="fifo.conf" description="FIFO Configuration">');
});

// ═══════════════════════════════════════════════════════════════════
//  4. TENANT ISOLATION & MULTI-TENANCY
// ═══════════════════════════════════════════════════════════════════

it('isolates SIP accounts by tenant in directory domain listing', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $domainA = TenantDomain::factory()->for($tenantA)->sipRealm()->create([
        'domain' => 'tenant-a.example.com',
        'enabled' => true,
    ]);
    $domainB = TenantDomain::factory()->for($tenantB)->sipRealm()->create([
        'domain' => 'tenant-b.example.com',
        'enabled' => true,
    ]);

    // Accounts for tenant A
    SipAccount::factory()->forTenant($tenantA->id)->create([
        'tenant_domain_id' => $domainA->id,
        'auth_username' => 'a_user_1',
        'enabled' => true,
    ]);

    // Accounts for tenant B
    SipAccount::factory()->forTenant($tenantB->id)->create([
        'tenant_domain_id' => $domainB->id,
        'auth_username' => 'b_user_1',
        'enabled' => true,
    ]);

    // Query tenant A domain — must NOT leak tenant B accounts
    $responseA = $this->get('/api/v1/xml-handler?'.fsDirectoryQuery([
        'domain' => 'tenant-a.example.com',
        'tag_name' => 'domain',
        'key_value' => 'tenant-a.example.com',
    ]));

    $responseA->assertOk();
    $xmlA = $responseA->content();
    expect($xmlA)->toContain('a_user_1');
    expect($xmlA)->not->toContain('b_user_1');

    // Query tenant B domain — must NOT leak tenant A accounts
    $responseB = $this->get('/api/v1/xml-handler?'.fsDirectoryQuery([
        'domain' => 'tenant-b.example.com',
        'tag_name' => 'domain',
        'key_value' => 'tenant-b.example.com',
    ]));

    $responseB->assertOk();
    $xmlB = $responseB->content();
    expect($xmlB)->toContain('b_user_1');
    expect($xmlB)->not->toContain('a_user_1');
});

it('isolates dialplan rules by tenant context', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $contextA = app(DialplanContext::class)->internal((string) $tenantA->id);
    $contextB = app(DialplanContext::class)->internal((string) $tenantB->id);

    Dialplan::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Tenant A Internal',
        'context' => $contextA,
        'enabled' => true,
    ]);
    Dialplan::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Internal',
        'context' => $contextB,
        'enabled' => true,
    ]);

    // Query tenant A context
    $responseA = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $contextA,
        'Caller-Destination-Number' => '3001',
    ]));

    $responseA->assertOk();
    expect($responseA->content())->toContain('Tenant A Internal');
    expect($responseA->content())->not->toContain('Tenant B Internal');

    // Query tenant B context
    $responseB = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $contextB,
        'Caller-Destination-Number' => '3001',
    ]));

    $responseB->assertOk();
    expect($responseB->content())->toContain('Tenant B Internal');
    expect($responseB->content())->not->toContain('Tenant A Internal');
});

it('includes all SIP profiles in sofia.conf regardless of query domain', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantDomain::factory()->for($tenantA)->sipRealm()->create([
        'domain' => 'profiles-a.example.com',
        'enabled' => true,
    ]);
    TenantDomain::factory()->for($tenantB)->sipRealm()->create([
        'domain' => 'profiles-b.example.com',
        'enabled' => true,
    ]);

    SipProfile::factory()->forTenant($tenantA->id)->create([
        'name' => 'tenant-a-internal',
        'settings' => ['sip-port' => '5060'],
    ]);
    SipProfile::factory()->forTenant($tenantB->id)->create([
        'name' => 'tenant-b-internal',
        'settings' => ['sip-port' => '5070'],
    ]);

    // Query tenant A domain — should include BOTH tenants' profiles
    // because FreeSWITCH sofia.conf serves all profiles to the runtime.
    $responseA = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
        'key_value' => 'sofia.conf',
        'domain' => 'profiles-a.example.com',
    ]));

    $responseA->assertOk();
    expect($responseA->content())->toContain('tenant-a-internal');
    expect($responseA->content())->toContain('tenant-b-internal');

    // Query tenant B domain — same result
    $responseB = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
        'key_value' => 'sofia.conf',
        'domain' => 'profiles-b.example.com',
    ]));

    $responseB->assertOk();
    expect($responseB->content())->toContain('tenant-b-internal');
    expect($responseB->content())->toContain('tenant-a-internal');
});

// ═══════════════════════════════════════════════════════════════════
//  5. ERROR HANDLING & EDGE CASES
// ═══════════════════════════════════════════════════════════════════

it('returns 404 for unknown XML handler section', function () {
    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        // phrases is a real section since the PIN routing work; use a
        // genuinely unknown section for the 404 contract.
        'section' => 'nonexistent',
        'FreeSWITCH-Hostname' => 'fs01.local',
    ]));

    $response->assertNotFound();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('Unknown section: nonexistent');
    expect($xml)->toContain('status="error"');
});

it('returns valid XML even with minimal request parameters', function () {
    // FreeSWITCH may send requests with only section and no optional params.
    // The handler must return valid XML in all cases.
    $response = $this->get('/api/v1/xml-handler?section=directory');

    $response->assertOk();
    assertValidFreeswitchXml($response->content());

    // When no domain/username is given, directory returns an empty section
    expect($response->content())->toContain('<section name="directory">');
});

it('escapes XML special characters in domain names to prevent injection', function () {
    $maliciousDomain = 'evil.com"><script>alert(1)</script>';

    $response = $this->get('/api/v1/xml-handler?'.fsDirectoryQuery([
        'domain' => $maliciousDomain,
        'tag_name' => 'domain',
        'key_value' => $maliciousDomain,
    ]));

    $response->assertOk();
    $xml = $response->content();

    // The raw malicious characters must not appear unescaped in output.
    // htmlspecialchars escapes <, >, &, ", ' but not parentheses.
    expect($xml)->not->toContain('<script>');
    expect($xml)->not->toContain('</script>');

    // The domain name should still appear (escaped)
    expect($xml)->toContain('evil.com');

    // XML must still be valid after escaping
    assertValidFreeswitchXml($xml);
});

it('handles POST method for directory requests as FreeSWITCH can send either', function () {
    extract(createTenantWithDomain('post-test.pbx'));

    SipAccount::factory()->forTenant($tenant->id)->create([
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'post_user',
        'enabled' => true,
    ]);

    $response = $this->post('/api/v1/xml-handler', [
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'post-test.pbx',
        'key_value' => 'post-test.pbx',
    ]);

    $response->assertOk();
    assertValidFreeswitchXml($response->content());
    expect($response->content())->toContain('post_user');
});

it('handles concurrent lookup of the same user across multiple domains', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    $domain1 = TenantDomain::factory()->for($tenant1)->sipRealm()->create([
        'domain' => 'domain1.example.com',
        'enabled' => true,
    ]);
    $domain2 = TenantDomain::factory()->for($tenant2)->sipRealm()->create([
        'domain' => 'domain2.example.com',
        'enabled' => true,
    ]);

    // Same username in two different tenants — must resolve correctly per domain
    SipAccount::factory()->forTenant($tenant1->id)->create([
        'tenant_domain_id' => $domain1->id,
        'auth_username' => 'shared_user',
        'auth_password' => 'pass_tenant1',
        'user_context' => 'ctx_1',
        'enabled' => true,
    ]);
    SipAccount::factory()->forTenant($tenant2->id)->create([
        'tenant_domain_id' => $domain2->id,
        'auth_username' => 'shared_user',
        'auth_password' => 'pass_tenant2',
        'user_context' => 'ctx_2',
        'enabled' => true,
    ]);

    // Look up in domain1
    $response1 = $this->get('/api/v1/xml-handler?'.fsDirectoryQuery([
        'domain' => 'domain1.example.com',
        'tag_name' => 'user',
        'sip_auth_username' => 'shared_user',
        'user' => 'shared_user',
    ]));

    $response1->assertOk();
    expect($response1->content())->toContain('ctx_1');
    expect($response1->content())->not->toContain('ctx_2');

    // Look up in domain2
    $response2 = $this->get('/api/v1/xml-handler?'.fsDirectoryQuery([
        'domain' => 'domain2.example.com',
        'tag_name' => 'user',
        'sip_auth_username' => 'shared_user',
        'user' => 'shared_user',
    ]));

    $response2->assertOk();
    expect($response2->content())->toContain('ctx_2');
    expect($response2->content())->not->toContain('ctx_1');
});

it('excludes disabled SIP profiles from sofia.conf but includes enabled ones', function () {
    extract(createTenantWithDomain('sofia-filter.pbx'));

    SipProfile::factory()->forTenant($tenant->id)->create([
        'name' => 'enabled-profile',
        'settings' => ['sip-port' => '5060'],
        'enabled' => true,
    ]);
    SipProfile::factory()->forTenant($tenant->id)->create([
        'name' => 'disabled-profile',
        'settings' => ['sip-port' => '5070'],
        'enabled' => false,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
        'key_value' => 'sofia.conf',
        'domain' => 'sofia-filter.pbx',
    ]));

    $response->assertOk();
    expect($response->content())->toContain('enabled-profile');
    expect($response->content())->not->toContain('disabled-profile');
});

it('can handle a full FreeSWITCH reload sequence', function () {
    // During a "reloadxml" or restart, FreeSWITCH sends multiple
    // configuration and directory requests in sequence. This test
    // simulates that sequence to ensure the handler is stateless.
    extract(createTenantWithDomain('reload-test.pbx'));

    SipProfile::factory()->forTenant($tenant->id)->create([
        'name' => 'reload-profile',
        'settings' => ['sip-port' => '5060'],
        'enabled' => true,
    ]);

    // Step 1: switch.conf
    $r1 = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery(['key_value' => 'switch.conf']));
    $r1->assertOk();

    // Step 2: sofia.conf
    $r2 = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
        'key_value' => 'sofia.conf',
        'domain' => 'reload-test.pbx',
    ]));
    $r2->assertOk();
    expect($r2->content())->toContain('reload-profile');

    // Step 3: db.conf
    $r3 = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery(['key_value' => 'db.conf']));
    $r3->assertOk();

    // Step 4: fifo.conf
    $r4 = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery(['key_value' => 'fifo.conf']));
    $r4->assertOk();

    // Step 5: directory domain lookup
    $r5 = $this->get('/api/v1/xml-handler?'.fsDirectoryQuery([
        'domain' => 'reload-test.pbx',
        'tag_name' => 'domain',
        'key_value' => 'reload-test.pbx',
    ]));
    $r5->assertOk();

    // All responses must be valid XML
    foreach ([$r1, $r2, $r3, $r4, $r5] as $r) {
        assertValidFreeswitchXml($r->content());
    }
});

// ─── Phase 4: Additional Contributor Fixture Tests ───────────────

it('includes feature code XML with actual FreeSWITCH application when configured', function () {
    extract(createTenantWithDomain('fc.example.com'));
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    // Feature code with mapped application (voicemail access)
    FeatureCode::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'voicemail_access',
        'code' => '*97',
        'application' => 'voicemail',
        'application_data' => 'default ${domain} ${caller_id_number}',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '*97',
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('feature_voicemail_access');
    expect($xml)->toContain('expression="^\\*97$"');
    expect($xml)->toContain('application="voicemail"');
    expect($xml)->not->toContain('application="log"');
});

it('falls back to log-only for feature codes without an application', function () {
    extract(createTenantWithDomain('fc2.example.com'));
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    // Feature code without mapped application (custom/unknown)
    FeatureCode::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'custom_code',
        'code' => '*99',
        'application' => null,
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '*99',
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('feature_custom_code');
    expect($xml)->toContain('application="log"');
});

it('includes call forward XML — unconditional type', function () {
    extract(createTenantWithDomain('cf.example.com'));
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    $fw = CallForward::factory()->create([
        'tenant_id' => $tenant->id,
        'forward_type' => 'unconditional',
        'destination' => 'sofia/external/+14155551212',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => $fw->extension_uuid,
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('forward_unconditional_'.$fw->extension_uuid);
    expect($xml)->toContain('application="bridge"');
    expect($xml)->toContain('+14155551212');
});

it('includes call forward XML — no-answer type with timeout', function () {
    extract(createTenantWithDomain('cf2.example.com'));
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    $fw = CallForward::factory()->create([
        'tenant_id' => $tenant->id,
        'forward_type' => 'no-answer',
        'destination' => 'user/2002',
        'ring_timeout' => 25,
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => $fw->extension_uuid,
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('forward_no-answer_'.$fw->extension_uuid);
    expect($xml)->toContain('call_timeout=25');
    expect($xml)->toContain('hangup_after_bridge=true');
});

it('includes emergency dialplan XML with caller ID override', function () {
    extract(createTenantWithDomain('e911.example.com'));
    $context = app(DialplanContext::class)->public((string) $tenant->id);

    Emergency::factory()->create([
        'tenant_id' => $tenant->id,
        'caller_id' => '+14155550123',
        'address' => '123 Main St, Springfield',
        'latitude' => 37.7749,
        'longitude' => -122.4194,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '911',
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('emergency_911');
    expect($xml)->toContain('effective_caller_id_number=+14155550123');
    expect($xml)->toContain('123 Main St');
});

it('includes time condition XML with time-based routing', function () {
    extract(createTenantWithDomain('tc.example.com'));
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    TimeCondition::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'business_hours',
        'timezone' => 'America/Chicago',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsDialplanQuery([
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '2000',
        'Caller-Domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('time_cond_business_hours');
});

// ─── Phase 6: Configuration File Generators ──────────────────────

it('serves ivr.conf with tenant IVR menu entries', function () {
    extract(createTenantWithDomain('ivr-config.example.com'));

    $menu = IvrMenu::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'main_menu',
        'greeting' => '/sounds/welcome.wav',
        'enabled' => true,
    ]);
    IvrMenuOption::factory()->create([
        'ivr_menu_id' => $menu->id,
        'digit' => '1',
        'action' => 'transfer',
        'action_data' => '1000 XML default',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
        'key_value' => 'ivr.conf',
        'domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('ivr.conf')
        ->toContain('<menu name="main_menu"')
        ->toContain('greet-long="/sounds/welcome.wav"')
        ->toContain('action="menu-exec-app"')
        ->toContain('digits="1"');
});

it('serves conference.conf with tenant conference profiles', function () {
    extract(createTenantWithDomain('conf-config.example.com'));

    Conference::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'boardroom',
        'profile' => 'wideband',
        'pin' => '4321',
        'max_members' => 25,
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
        'key_value' => 'conference.conf',
        'domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('conference.conf')
        ->toContain('<profile name="boardroom">')
        ->toContain('conference-profile" value="wideband"')
        ->toContain('max-members" value="25"')
        ->toContain('pin" value="4321"');
});

it('serves callcenter.conf with tenant queue definitions', function () {
    extract(createTenantWithDomain('cc-config.example.com'));

    Queue::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'support_queue',
        'strategy' => 'ring-all',
        'timeout' => 45,
        'music_on_hold' => '$${hold_music}',
        'enabled' => true,
    ]);

    $response = $this->get('/api/v1/xml-handler?'.fsConfigurationQuery([
        'key_value' => 'callcenter.conf',
        'domain' => $domain->domain,
    ]));

    $response->assertOk();
    $xml = $response->content();
    assertValidFreeswitchXml($xml);

    expect($xml)->toContain('callcenter.conf')
        ->toContain('<queue name="support_queue@default">')
        ->toContain('strategy" value="ring-all"')
        ->toContain('moh-sound"')
        ->toContain('timeout" value="45"');
});
