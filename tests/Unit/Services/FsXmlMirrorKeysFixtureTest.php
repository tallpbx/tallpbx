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
        if (! isset($case['expected_keys'], $case['identity'])) {
            continue;
        }

        expect(FsXmlMirrorKeys::tenantLookup($case['identity']['domain'], $case['identity']['user']))
            ->toBe($case['expected_keys']['tenant'])
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

    // Six cases carry expected keys (four hits, truncated, empty, foreign).
    expect($checked)->toBeGreaterThanOrEqual(6);
});
