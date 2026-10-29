<?php

declare(strict_types=1);

use App\Support\PermissionSyncPolicy;

it('refuses to sync on the mod_xml_curl hot path', function () {
    expect((new PermissionSyncPolicy)->shouldSync('api/v1/xml-handler', false, null, 'hash-a'))->toBeFalse();
});

it('refuses to sync when the cached registry hash still matches', function () {
    expect((new PermissionSyncPolicy)->shouldSync('panel/extensions', false, 'hash-a', 'hash-a'))->toBeFalse();
});

it('syncs when the cached hash is missing or stale', function () {
    expect((new PermissionSyncPolicy)->shouldSync('panel/extensions', false, null, 'hash-a'))->toBeTrue()
        ->and((new PermissionSyncPolicy)->shouldSync('panel/extensions', false, 'hash-a', 'hash-b'))->toBeTrue();
});

it('decides console runs by hash only, never by path', function () {
    expect((new PermissionSyncPolicy)->shouldSync(null, true, 'hash-a', 'hash-b'))->toBeTrue();
});
