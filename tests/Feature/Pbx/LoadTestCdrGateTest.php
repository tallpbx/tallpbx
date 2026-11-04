<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| CDR Gate for SIP Cells
|--------------------------------------------------------------------------
|
| An XML cell makes no calls, so it can only prove the CDR pipeline was left
| alone. A SIP cell makes real calls, and the number that matters is whether
| each completed call left a record behind. Without this gate a cell could
| report 100 % call success while the CDR worker was dead and nobody would
| notice until an operator went looking for a bill.
|
| These checks pin the verdict rules and the wiring that applies them.
|
*/

const CDR_GATE_SCRIPT = 'load-testing/scripts/tools/cdr-gate.sh';
const CDR_GATE_SCRATCH = 'storage/framework/testing/load-tests/cdr-gate';

/**
 * Run the CDR gate tool with the given arguments.
 *
 * @param  array<int, string>  $arguments  Command-line arguments for the tool
 * @return Process The finished process, for output and exit-code assertions
 */
function cdrGateProcess(array $arguments): Process
{
    $process = new Process(['bash', base_path(CDR_GATE_SCRIPT), ...$arguments], base_path());
    $process->run();

    return $process;
}

/**
 * Write a SIPp step log whose final tallies are the given numbers.
 *
 * The layout matches what SIPp 3.7 prints at the end of a run, because the
 * parser reads the cumulative column of that screen.
 *
 * @param  int  $successful  Completed calls the run reports
 * @param  int  $failed  Failed calls the run reports
 * @return string Path of the log file
 */
function writeSippStepLog(int $successful, int $failed): string
{
    File::ensureDirectoryExists(base_path(CDR_GATE_SCRATCH));

    $path = base_path(CDR_GATE_SCRATCH.'/extension-calls.log');

    File::put($path, implode("\n", [
        '------------------------------ Test Terminated --------------------------------',
        '  Incoming calls created |        0                  |        0                 ',
        '  Outgoing calls created |        1                  |        8                 ',
        '  Successful call        |        0                  |        '.$successful.'                 ',
        '  Failed call            |        0                  |        '.$failed.'                 ',
        '------------------------------ Test Terminated --------------------------------',
        '',
    ]));

    return $path;
}

describe('cdr gate', function (): void {
    afterEach(function (): void {
        File::deleteDirectory(base_path(CDR_GATE_SCRATCH));
    });

    it('is a syntactically valid bash script', function (): void {
        $process = new Process(['bash', '-n', base_path(CDR_GATE_SCRIPT)], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    });

    it('reads the completed and failed call tallies out of a SIPp log', function (): void {
        $log = writeSippStepLog(7, 1);

        $process = cdrGateProcess(['sipp-counts', $log]);

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(trim($process->getOutput()))->toBe('7 1');
    });

    it('refuses to guess when a log carries no tally at all', function (): void {
        File::ensureDirectoryExists(base_path(CDR_GATE_SCRATCH));
        $log = base_path(CDR_GATE_SCRATCH.'/crashed.log');
        File::put($log, "sipp: command failed before starting\n");

        $process = cdrGateProcess(['sipp-counts', $log]);

        // A crashed run must not read as "zero calls, nothing to check": that
        // would silently pass the gate for a cell that never measured anything.
        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('no-tally');
    });

    it('passes a cell whose completed calls all left a record', function (): void {
        $process = cdrGateProcess([
            'verdict', '--before=10', '--after=14', '--successful=2', '--failed=0',
            '--label=h1-1c1g/v-fast/sip-cps5',
        ]);

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())->toContain('CDR-GATE ok')
            ->and($process->getOutput())->toContain('rows=4');
    });

    it('fails a cell whose calls produced no record at all', function (): void {
        $process = cdrGateProcess([
            'verdict', '--before=10', '--after=10', '--successful=2', '--failed=0',
            '--label=h1-1c1g/v-fast/sip-cps5',
        ]);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getOutput())->toContain('CDR-GATE FAILED')
            ->and($process->getOutput())->toContain('reason=no-records');
    });

    it('fails a cell where some calls left no record', function (): void {
        $process = cdrGateProcess([
            'verdict', '--before=10', '--after=11', '--successful=3', '--failed=0',
            '--label=h1-1c1g/v-fast/sip-cps5',
        ]);

        // Every completed call ends an a-leg, and an a-leg always has a
        // record. Fewer rows than completed calls means the pipeline dropped
        // some, which is a data-integrity failure rather than a slow worker.
        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getOutput())->toContain('reason=partial')
            ->and($process->getOutput())->toContain('rows=1');
    });

    it('fails a cell that never placed a call, because it measured nothing', function (): void {
        $process = cdrGateProcess([
            'verdict', '--before=10', '--after=10', '--successful=0', '--failed=0',
            '--label=h1-1c1g/v-fast/sip-cps5',
        ]);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getOutput())->toContain('reason=no-calls');
    });

    it('waits for the rows the calls should have produced, not just for the count to look settled', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        preg_match('/^run_sip_phase\(\) \{.*?^\}/ms', $script, $match);
        $phase = $match[0] ?? '';

        // Measured: the pipeline had not started writing when the first cell
        // finished, so three identical samples read as "settled" while every
        // record was still in flight. The wait now knows how many rows the
        // completed calls require and keeps polling until they arrive.
        expect($phase)->toContain('cdrs_wait_for')
            ->and($script)->toContain('cdrs_wait_for()')
            ->and($phase)->not->toContain('cdrs_settled_count');
    });

    it('reads the call tallies before waiting on the database', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        preg_match('/^run_sip_phase\(\) \{.*?^\}/ms', $script, $match);
        $phase = $match[0] ?? '';

        // The wait needs to know how many rows to expect, and only SIPp's own
        // tally can say that.
        $tally = strpos($phase, 'sipp-counts');
        $wait = strpos($phase, 'cdrs_wait_for');

        expect($tally)->not->toBeFalse()
            ->and($wait)->not->toBeFalse()
            ->and($tally)->toBeLessThan($wait);
    });

    it('prepares the generator for every cell, not only the first', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        preg_match('/^run_sip_phase\(\) \{.*?^\}/ms', $script, $match);
        $phase = $match[0] ?? '';

        // Preparation seeds the tenant, which rewrites the outbound route to
        // the generator. Skipping it on later cells left the route pointing at
        // 127.0.0.1 on the PBX, where nothing listens, and every outbound
        // call answered 503.
        expect($phase)->toContain('sipp_prepare_generator')
            ->and($phase)->not->toContain('generator/prepared');
    });

    it('points the seeded outbound gateway at the generator', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        preg_match('/^sipp_prepare_generator\(\) \{.*?^\}/ms', $script, $match);
        $prepare = $match[0] ?? '';

        // The seed defaults the simulated gateway to 127.0.0.1, which on the
        // PBX is nothing: every outbound call then answers 503 before a single
        // packet reaches the generator.
        expect($prepare)->toContain('--sipp-host')
            ->and($prepare)->toContain('--sipp-port')
            ->and($script)->toContain('SIPP_UAS_HOST');
    });

    it('keeps the load-test tenant between runs instead of recreating it', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        preg_match('/^seed_tenant\(\) \{.*?^\}/ms', $script, $match);
        $seed = $match[0] ?? '';

        // `--reset` deletes the tenant and creates a new one, so the tenant id
        // changes on every invocation. The dialplan context carries that id,
        // so a registration made against the previous context routes into a
        // context that no longer exists and every outbound call answers 503.
        expect($seed)->not->toContain('--reset');
    });

    it('applies the gate to every SIP cell and records the verdict beside it', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        preg_match('/^run_sip_phase\(\) \{.*?^\}/ms', $script, $match);
        $phase = $match[0] ?? '';

        expect($phase)->toContain('cdr-gate.sh')
            ->and($phase)->toContain('cdr_row_count')
            ->and($phase)->toContain('cdr-gate.txt');
    });

    it('warms the directory before registering, because a cold lookup outlasts the retransmit timer', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        preg_match('/^run_sip_phase\(\) \{.*?^\}/ms', $script, $match);
        $phase = $match[0] ?? '';

        // Measured on the 1 vCPU tier: the first lookup for an extension costs
        // over a second, while SIPp retransmits after 500 ms. The retransmitted
        // REGISTER draws a second 401 that the scenario cannot place, so the
        // step fails on a timing artefact rather than on what it measures.
        expect($phase)->toContain('warm_directory')
            ->and($script)->toContain('find_user_xml');
    });
});
