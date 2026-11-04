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

    it('applies the gate to every SIP cell and records the verdict beside it', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        preg_match('/^run_sip_phase\(\) \{.*?^\}/ms', $script, $match);
        $phase = $match[0] ?? '';

        expect($phase)->toContain('cdr-gate.sh')
            ->and($phase)->toContain('cdr_row_count')
            ->and($phase)->toContain('cdr-gate.txt');
    });
});
