<?php

declare(strict_types=1);

use App\Services\Xml\FsXmlMirrorKeys;

/*
 * Parity guard. The JSON file is the shared contract between the PHP key
 * builder and scripts/freeswitch/tallpbx_xml_handler.lua; if either side's
 * format drifts, this test or the Lua harness fails.
 */

$fixtures = json_decode(
    (string) file_get_contents(__DIR__.'/../../fixtures/freeswitch/lua_key_fixtures.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

it('reproduces every fixture key string with the php key builder', function () use ($fixtures) {
    $checked = 0;

    foreach ($fixtures['cases'] as $case) {
        if (! isset($case['expected_keys'])) {
            continue;
        }

        // Dialplan cases carry the context and destination instead of a
        // domain/user identity, because the tenant travels inside the context.
        if (isset($case['context'])) {
            expect(FsXmlMirrorKeys::dialplan(
                $case['tenant_id'],
                $case['context'],
                $case['destination'],
                $case['version'],
                $case['epoch'],
            ))->toBe($case['expected_keys']['payload']);

            $checked++;

            continue;
        }

        if (! isset($case['identity'])) {
            continue;
        }

        expect(FsXmlMirrorKeys::tenantLookup($case['identity']['domain'], $case['identity']['user'], $case['epoch']))
            ->toBe($case['expected_keys']['tenant'])
            ->and(FsXmlMirrorKeys::globalVersion())
            ->toBe($case['expected_keys']['epoch'])
            ->and(FsXmlMirrorKeys::version($case['tenant_id']))
            ->toBe($case['expected_keys']['version'])
            ->and(FsXmlMirrorKeys::directory(
                $case['tenant_id'],
                $case['request']['tag_name'],
                $case['identity']['domain'],
                $case['identity']['user'],
                $case['version'],
            ))
            ->toBe($case['expected_keys']['payload']);

        $checked++;
    }

    // Directory hits plus the dialplan hits carry expected keys; the miss
    // cases deliberately carry none, because a miss must query no payload.
    expect($checked)->toBeGreaterThanOrEqual(8);
});
