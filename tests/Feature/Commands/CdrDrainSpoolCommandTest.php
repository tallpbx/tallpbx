<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Testing\PendingCommand;

/*
 * The spool is the last line of defence: deliveries that exhausted their
 * retries land in err-log-dir as raw JSON files, and this command reconciles
 * them into the same `cdrs` rows the stream digest produces. Files are
 * removed only after the row is committed or the payload is safely
 * quarantined, so an interrupted drain retries instead of losing records.
 */

/**
 * Write one spooled payload file into the test spool directory.
 */
function spoolFile(string $dir, string $name, string $contents): void
{
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents($dir.'/'.$name, $contents);
}

/**
 * Build one captured payload with a resolvable tenant domain overlaid.
 */
function spoolPayload(string $leg): string
{
    return cdrFixture($leg, ['domain_name' => 'drain.example']);
}

beforeEach(function () {
    $this->spool = sys_get_temp_dir().'/cdr-spool-'.uniqid();
    $this->quarantine = sys_get_temp_dir().'/cdr-quarantine-'.uniqid();

    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'drain.example']);
});

/**
 * Run the drain command against the test directories.
 */
function runDrain(array $options = []): PendingCommand
{
    return test()->artisan('cdr:drain-spool', array_merge([
        '--spool-dir' => test()->spool,
        '--quarantine-dir' => test()->quarantine,
        '--grace' => 0,
    ], $options));
}

it('drains spooled records into cdrs and removes the files', function () {
    spoolFile($this->spool, '8682.cdr.json', spoolPayload('a'));
    spoolFile($this->spool, '97a4.cdr.json', spoolPayload('b'));

    runDrain(['--batch' => 100])
        ->expectsOutputToContain('Drained 2 spooled record(s)')
        ->assertSuccessful();

    $rows = DB::table('cdrs')->orderBy('leg')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('leg')->all())->toBe(['a', 'b'])
        ->and($rows->firstWhere('leg', 'a')->b_leg_uuid)->toBe('97a4bd05-3ad2-474e-832a-7bdaae999bb0')
        ->and(glob($this->spool.'/*.json'))->toBeEmpty();
});

it('quarantines unparseable files instead of dropping them', function () {
    spoolFile($this->spool, 'broken.cdr.json', '{"variables":');

    runDrain()
        ->expectsOutputToContain('quarantined 1')
        ->assertSuccessful();

    expect(glob($this->quarantine.'/*.json'))->toHaveCount(1)
        ->and(glob($this->spool.'/*.json'))->toBeEmpty()
        ->and(DB::table('cdrs')->count())->toBe(0);
});

it('quarantines records whose domain belongs to no tenant', function () {
    // tenant_id is NOT NULL and constrained: an unattributable record is kept
    // for review rather than inserted against the wrong tenant.
    spoolFile($this->spool, 'no-tenant.cdr.json', cdrFixture('a', ['domain_name' => 'nobody.example']));

    runDrain()
        ->expectsOutputToContain('quarantined 1')
        ->assertSuccessful();

    expect(glob($this->quarantine.'/*.json'))->toHaveCount(1)
        ->and(DB::table('cdrs')->count())->toBe(0);
});

it('skips files that may still be open', function () {
    // mod_json_cdr writes the file over several writes; a fresh mtime means
    // the file may not be complete yet.
    spoolFile($this->spool, 'fresh.cdr.json', spoolPayload('a'));

    runDrain(['--grace' => 3600])
        ->expectsOutputToContain('Skipped 1 file(s)')
        ->assertSuccessful();

    expect(glob($this->spool.'/*.json'))->toHaveCount(1)
        ->and(DB::table('cdrs')->count())->toBe(0);
});

it('keeps the spooled file when the quarantine copy fails', function () {
    // A file in the quarantine path makes the copy impossible; the record
    // must stay in the spool for the next run rather than disappear.
    $blocked = $this->quarantine;
    file_put_contents($blocked, 'not a directory');

    spoolFile($this->spool, 'broken.cdr.json', '{"variables":');

    runDrain()
        ->expectsOutputToContain('quarantined 0')
        ->assertSuccessful();

    expect(glob($this->spool.'/*.json'))->toHaveCount(1);
});

it('reports an empty spool without failing', function () {
    mkdir($this->spool, 0775, true);

    runDrain()
        ->expectsOutputToContain('No spooled records')
        ->assertSuccessful();
});
