<?php

declare(strict_types=1);

use App\Services\Xml\FsXmlMirrorKeys;

/*
 * The key strings below are the contract with
 * scripts/freeswitch/tallpbx_xml_handler.lua. A format change here that is
 * not mirrored in the Lua script turns every lookup into a cache miss, so
 * these expectations are intentionally literal.
 */

test('defines fixed pbx:fsxml namespace constant', function () {
    expect(FsXmlMirrorKeys::NAMESPACE)->toBe('pbx:fsxml');
});

test('builds namespaced version keys for tenant and global revisions', function () {
    expect(FsXmlMirrorKeys::version(7))->toBe('pbx:fsxml:ver:7')
        ->and(FsXmlMirrorKeys::globalVersion())->toBe('pbx:fsxml:ver:global');
});

test('builds tenant lookup key from domain and user', function () {
    expect(FsXmlMirrorKeys::tenantLookup('a.com', '1001'))
        ->toBe('pbx:fsxml:tenant:user:a.com:1001');
});

test('builds directory payload key embedding tenant, tag name, domain, user, and routing version', function () {
    expect(FsXmlMirrorKeys::directory(7, 'domain', 'a.com', '1001', 3))
        ->toBe('pbx:fsxml:dir:7:domain:a.com:1001:v3')
        ->and(FsXmlMirrorKeys::directory(7, 'user', 'a.com', '1001', 3))
        ->toBe('pbx:fsxml:dir:7:user:a.com:1001:v3');
});
