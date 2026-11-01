<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\XmlHandlerFastBootstrap;
use Illuminate\Http\Request;
use Modules\Extensions\Models\Extension;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Models\RingGroupExtension;
use Modules\SipAccounts\Models\SipAccount;

/*
 * Byte-equality gate for the fast XML-handler front controller (AO-B7).
 *
 * The fast path boots the container with a small provider allowlist instead of
 * the ~90 providers a normal request loads. That is only safe if it produces
 * exactly the same XML, so this test compares the fast bootstrap's output with
 * the normal route response, byte for byte.
 *
 * The bootstrap is exercised directly rather than through public/xml-handler.php
 * because that file is only reachable through nginx in production; the file
 * itself stays a thin shim around this class.
 *
 * The fast bootstrap reuses the already-connected database manager when one is
 * bound, so the :memory: test schema and rows stay visible to it.
 */

beforeEach(function (): void {
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.directory_cache_store' => 'array',
        'freeswitch.xml_handler.directory_cache_ttl' => 0,
    ]);
});

it('returns byte-identical XML for a domain directory lookup', function (): void {
    $uri = '/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'example.com',
        'key_value' => 'example.com',
    ]);

    $normal = $this->get($uri);
    $normal->assertOk();

    $fast = XmlHandlerFastBootstrap::handle(Request::create($uri, 'GET'));

    expect($fast->getContent())->toBe($normal->getContent());
});

it('returns byte-identical XML for a not-found user lookup', function (): void {
    $uri = '/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'user',
        'key_value' => '1001',
        'sip_auth_username' => 'user1001',
    ]);

    $normal = $this->get($uri);
    $normal->assertOk();

    $fast = XmlHandlerFastBootstrap::handle(Request::create($uri, 'GET'));

    expect($fast->getContent())->toBe($normal->getContent());
});

it('returns byte-identical XML for a dialplan with a ring group contributor', function (): void {
    [$tenant] = seedDirectoryTenant();

    $extension = Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '2101',
    ]);

    $group = RingGroup::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'support_team',
        'strategy' => 'simultaneous',
        'ring_timeout' => 20,
        'enabled' => true,
    ]);

    RingGroupExtension::factory()->create([
        'ring_group_id' => $group->id,
        'extension_uuid' => $extension->id,
        'position' => 1,
    ]);

    $uri = '/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => 'tenant_'.$tenant->id.'_internal',
        'Caller-Destination-Number' => 'support_team',
        'Caller-Caller-ID-Number' => 'user5001',
        'domain' => 'sip.fast-path.test',
        'sip_auth_username' => 'user5001',
    ]);

    $normal = $this->get($uri);
    $normal->assertOk();

    $fast = XmlHandlerFastBootstrap::handle(Request::create($uri, 'GET'));

    expect($fast->getContent())->toBe($normal->getContent());
});

/**
 * Create a tenant with a SIP domain and one registered SIP account.
 *
 * @return array{0: Tenant, 1: TenantDomain, 2: SipAccount}
 */
function seedDirectoryTenant(): array
{
    $tenant = Tenant::factory()->create();

    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'sip.fast-path.test',
        'enabled' => true,
    ]);

    $account = SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'user5001',
        'user_context' => 'tenant_'.$tenant->id.'_internal',
        'enabled' => true,
    ]);

    return [$tenant, $domain, $account];
}

it('returns byte-identical XML for a seeded domain directory', function (): void {
    seedDirectoryTenant();

    $uri = '/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'domain',
        'domain' => 'sip.fast-path.test',
        'key_value' => 'sip.fast-path.test',
    ]);

    $normal = $this->get($uri);
    $normal->assertOk();

    $fast = XmlHandlerFastBootstrap::handle(Request::create($uri, 'GET'));

    expect($fast->getContent())->toBe($normal->getContent());
});

it('returns byte-identical XML for a seeded user entry', function (): void {
    seedDirectoryTenant();

    $uri = '/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'tag_name' => 'user',
        'domain' => 'sip.fast-path.test',
        'key_value' => 'user5001',
        'sip_auth_username' => 'user5001',
    ]);

    $normal = $this->get($uri);
    $normal->assertOk();

    $fast = XmlHandlerFastBootstrap::handle(Request::create($uri, 'GET'));

    expect($fast->getContent())->toBe($normal->getContent());
});
