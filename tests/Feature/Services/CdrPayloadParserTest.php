<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Cdr\CdrPayloadParser;
use Illuminate\Support\Str;

/*
 * The parser turns one raw mod_json_cdr document into a cdrs row. The field
 * mapping is verified against real payloads captured from a bridged call on
 * the reference host (tests/Fixtures/cdr/{a,b}-leg.json), which is what the
 * plan's review asked for: no guessed variable names. The cdrFixture helper
 * is shared through tests/Pest.php.
 */

/**
 * Build a parser that quarantines into a throwaway directory.
 */
function cdrParser(string $quarantineDir): CdrPayloadParser
{
    return new CdrPayloadParser($quarantineDir);
}

it('maps the captured a-leg onto a cdrs row', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'capture.example']);

    $row = cdrParser(sys_get_temp_dir().'/cdr-quarantine-'.uniqid())
        ->parse(cdrFixture('a', ['domain_name' => 'capture.example']));

    expect($row)->not->toBeNull()
        // The originating leg is identified by the verified originator markers.
        ->and($row['leg'])->toBe('a')
        ->and($row['call_uuid'])->toBe('8682bd04-2fbd-4dcb-a759-68973a83bae5')
        ->and($row['b_leg_uuid'])->toBe('97a4bd05-3ad2-474e-832a-7bdaae999bb0')
        ->and($row['caller_id'])->toBe('5551111')
        ->and($row['caller_id_name'])->toBe('Outbound Call')
        ->and($row['destination'])->toBe('5551111')
        ->and($row['duration'])->toBe(15)
        ->and($row['billsec'])->toBe(15)
        ->and($row['hangup_cause'])->toBe('NORMAL_CLEARING')
        ->and($row['direction'])->toBe('outbound')
        ->and($row['tenant_id'])->toBe($tenant->id)
        // Bulk inserts bypass HasUuids, so the id must be assigned explicitly.
        ->and(Str::isUuid((string) $row['id']))->toBeTrue()
        // Stamps come from the epoch variables.
        ->and($row['start_stamp']->getTimestamp())->toBe(1793580877)
        ->and($row['end_stamp']->getTimestamp())->toBe(1793580892)
        // The untouched payload is stored as a string for the raw diagnostics view.
        ->and(is_string($row['json_data']))->toBeTrue()
        ->and($row['json_data'])->toContain('8682bd04-2fbd-4dcb-a759-68973a83bae5');
});

it('classifies the bridged leg as the b-side and links it back', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'capture.example']);

    $row = cdrParser(sys_get_temp_dir().'/cdr-quarantine-'.uniqid())
        ->parse(cdrFixture('b', ['domain_name' => 'capture.example']));

    expect($row)->not->toBeNull()
        ->and($row['leg'])->toBe('b')
        ->and($row['call_uuid'])->toBe('97a4bd05-3ad2-474e-832a-7bdaae999bb0')
        // The b-leg's bond points back at the originating channel.
        ->and($row['b_leg_uuid'])->toBe('8682bd04-2fbd-4dcb-a759-68973a83bae5')
        ->and($row['destination'])->toBe('5552222');
});

it('combines record_path and record_name into one path', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'capture.example']);

    $row = cdrParser(sys_get_temp_dir().'/cdr-quarantine-'.uniqid())->parse(cdrFixture('a', [
        'domain_name' => 'capture.example',
        'record_path' => '/var/lib/tallpbx/media/store/recordings',
        'record_name' => 'call-8682.wav',
    ]));

    expect($row['record_path'])->toBe('/var/lib/tallpbx/media/store/recordings/call-8682.wav');
});

it('returns null and quarantines a malformed payload', function () {
    $dir = sys_get_temp_dir().'/cdr-quarantine-'.uniqid();
    $parser = cdrParser($dir);

    // parse() rejects; the digest cycle then preserves the payload on disk.
    expect($parser->parse('{"variables":'))->toBeNull()
        ->and($parser->quarantine('1-1', '{"variables":'))->toBeTrue()
        ->and(glob($dir.'/*.json'))->toHaveCount(1);
});

it('returns null and quarantines a payload without a call uuid', function () {
    $dir = sys_get_temp_dir().'/cdr-quarantine-'.uniqid();
    $parser = cdrParser($dir);

    // A record without a uuid can never be deduplicated, so it must not be
    // inserted with null in the unique key.
    expect($parser->parse(cdrFixture('a', ['uuid' => null, 'domain_name' => 'capture.example'])))->toBeNull()
        ->and($parser->quarantine('1-2', cdrFixture('a')))->toBeTrue()
        ->and(glob($dir.'/*.json'))->toHaveCount(1);
});

it('returns null and quarantines a payload whose domain belongs to no tenant', function () {
    $dir = sys_get_temp_dir().'/cdr-quarantine-'.uniqid();
    $parser = cdrParser($dir);

    // tenant_id is NOT NULL and constrained: an unattributable record is kept
    // for review rather than inserted against the wrong tenant.
    expect($parser->parse(cdrFixture('a', ['domain_name' => 'unknown.example'])))->toBeNull()
        ->and($parser->quarantine('1-3', cdrFixture('a')))->toBeTrue()
        ->and(glob($dir.'/*.json'))->toHaveCount(1);
});
