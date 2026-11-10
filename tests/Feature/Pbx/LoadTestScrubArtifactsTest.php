<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Artifact Address Scrubber
|--------------------------------------------------------------------------
|
| The measurement hosts are disposable and their addresses are not public
| knowledge. Every committed artifact has to show them as x.x.x.NNN, so a
| reader can tell two hosts apart without being able to reach either.
|
| These checks pin the scrub: the address is replaced everywhere it appears,
| the last octet is kept, and other addresses are left alone.
|
*/

const SCRUB_SCRIPT = 'load-testing/scripts/tools/scrub-artifacts.sh';
const SCRUB_SCRATCH = 'storage/framework/testing/load-tests/scrub';

/**
 * Run the scrubber over a directory.
 *
 * @param  array<int, string>  $arguments  Command-line arguments for the tool
 * @return Process The finished process, for output assertions
 */
function scrubProcess(array $arguments): Process
{
    $process = new Process(['bash', base_path(SCRUB_SCRIPT), ...$arguments], base_path());
    $process->run();

    return $process;
}

describe('artifact address scrubber', function (): void {
    afterEach(function (): void {
        File::deleteDirectory(base_path(SCRUB_SCRATCH));
    });

    it('is a syntactically valid bash script', function (): void {
        $process = new Process(['bash', '-n', base_path(SCRUB_SCRIPT)], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    });

    it('replaces the host address everywhere it appears', function (): void {
        File::ensureDirectoryExists(base_path(SCRUB_SCRATCH.'/logs'));

        File::put(base_path(SCRUB_SCRATCH.'/logs/register.log'), implode("\n", [
            'Via: SIP/2.0/UDP 146.190.245.203:5066;branch=z9hG4bK-1',
            'Call-ID: 1-4022@146.190.245.203',
            'Contact: <sip:2000@146.190.245.203:5066>',
            '',
        ]));

        $process = scrubProcess([base_path(SCRUB_SCRATCH), '146.190.245.203']);

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $scrubbed = (string) file_get_contents(base_path(SCRUB_SCRATCH.'/logs/register.log'));

        expect($scrubbed)->toContain('x.x.x.203')
            ->and($scrubbed)->not->toContain('146.190.245.203');
    });

    it('leaves other addresses and non-address text alone', function (): void {
        File::ensureDirectoryExists(base_path(SCRUB_SCRATCH));

        File::put(base_path(SCRUB_SCRATCH.'/summary.md'), implode("\n", [
            '- register: passed',
            'Generator 137.184.160.16 answered the calls',
            'PBX 146.190.245.203 registered the extensions',
            '',
        ]));

        $process = scrubProcess([base_path(SCRUB_SCRATCH), '146.190.245.203']);

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $scrubbed = (string) file_get_contents(base_path(SCRUB_SCRATCH.'/summary.md'));

        expect($scrubbed)->toContain('x.x.x.203')
            ->and($scrubbed)->toContain('137.184.160.16')
            ->and($scrubbed)->toContain('- register: passed');
    });

    it('finds the addresses by itself when the caller names none', function (): void {
        File::ensureDirectoryExists(base_path(SCRUB_SCRATCH));

        File::put(base_path(SCRUB_SCRATCH.'/manifest.json'), '{"host":"162.243.62.138"}');

        $process = scrubProcess([base_path(SCRUB_SCRATCH)]);

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and((string) file_get_contents(base_path(SCRUB_SCRATCH.'/manifest.json')))
            ->toContain('x.x.x.138');
    });

    it('keeps driver scripts free of hardcoded public IPv4 addresses and private key paths', function (): void {
        foreach (glob(base_path('load-testing/scripts/drivers/*.sh')) as $driverFile) {
            $contents = (string) file_get_contents($driverFile);

            expect($contents)
                ->not->toMatch('/\b(?!127\.|10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/')
                ->not->toContain('ppx2-client');
        }
    });

    it('keeps load-testing documentation free of hardcoded public IPv4 addresses and documents active matrix standards', function (): void {
        foreach (glob(base_path('load-testing/*.md')) as $mdFile) {
            $contents = (string) file_get_contents($mdFile);

            expect($contents)
                ->not->toMatch('/\b(?!127\.|10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/')
                ->not->toContain('ppx2-client');
        }

        $readme = (string) file_get_contents(base_path('load-testing/README.md'));
        expect($readme)->toContain('v-fast-default')
            ->and($readme)->toContain('1000x25');

        $configurations = (string) file_get_contents(base_path('load-testing/configurations.md'));
        expect($configurations)->toContain('v-fast-default')
            ->and($configurations)->toContain('1000x25');
    });

    it('keeps docs markdown files free of un-obfuscated public IPs, hardcoded key names, and doubled load-testing prefixes', function (): void {
        $doublePrefix = 'load-testing/'.'load-testing/';
        $keyName = 'ppx2-'.'client';

        // Only the two active working documents are skipped, by exact name: a
        // broad *evaluation* pattern would also skip committed historical
        // reports (for example todays-commit-evaluation.md).
        $activeWorkingDocs = [
            '5.x-evaluation-implementation-plan.md',
            'evaluation-of-5.x-implementation-plan.md',
        ];

        foreach (glob(base_path('docs/*.md')) as $docFile) {
            if (in_array(basename($docFile), $activeWorkingDocs, true)) {
                continue;
            }

            $contents = (string) file_get_contents($docFile);

            expect($contents)
                ->not->toMatch('/\b(?!127\.|10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.|0\.0\.0\.0|255\.|198\.51\.100\.|203\.0\.113\.|192\.0\.2\.|1\.1\.1\.1|8\.8\.)\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/')
                ->not->toContain($keyName)
                ->not->toContain($doublePrefix);
        }
    });
});
