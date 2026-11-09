<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Load-Test Artifact Hygiene
|--------------------------------------------------------------------------
|
| Raw run directories are working data, and their per-cell fragments are
| never repository content (load-testing/README.md rule 6). Three
| independent guards keep the fragments out:
|
|   1. `.gitignore` keeps them unstaged by accident.
|   2. `scripts/git-hooks/pre-commit` refuses a commit that stages them.
|   3. This test fails the suite if either guard is removed.
|
| The rule exists because one series produces thousands of per-cell files
| (SIPp traces, CSVs, JSON reports, transport logs) that bloat every clone
| and are unreviewable. What IS repository content: the distilled,
| cross-scenario comparison data under `load-testing/results/consolidated/`
| (per-cell metrics, per-service CPU/RAM, per-call latency) and the written
| record under `docs/`. The guards allow-list exactly that directory.
|
*/

/**
 * Run one git command in the repository root.
 *
 * @param  array<int, string>  $arguments  Command and arguments
 */
function loadTestHygieneGit(array $arguments): Process
{
    $process = new Process(['git', ...$arguments], base_path());
    $process->run();

    return $process;
}

it('ignores raw fragments but keeps the consolidated data files visible', function (): void {
    $ignored = [
        'load-testing/results/demo-series/h1-1c1g/sip-cps5/sipp.log',
        'load-testing/results/demo-series/h1-1c1g/v-fast/sip-cps5-cpu.csv',
        'storage/app/load-tests/demo-series/sipp-users.csv',
    ];

    foreach ($ignored as $path) {
        $process = loadTestHygieneGit(['check-ignore', '-q', $path]);

        expect($process->getExitCode())->toBe(
            0,
            "{$path} must be git-ignored — raw fragments are never committed",
        );
    }

    // The distilled, cross-scenario data files are repository content: they
    // carry the whole comparable measurement set in a few documents.
    $process = loadTestHygieneGit(['check-ignore', '-q', 'load-testing/results/consolidated/worker-sweep-demo-cells.csv']);

    expect($process->getExitCode())->toBe(
        1,
        'consolidated data files must NOT be git-ignored — they are the versioned measurement set',
    );
});

it('ships an executable pre-commit hook that refuses raw artifacts', function (): void {
    $hook = base_path('scripts/git-hooks/pre-commit');

    expect(is_file($hook))->toBeTrue('The pre-commit guard must exist')
        ->and(is_executable($hook))->toBeTrue('The guard must be executable');

    $contents = (string) file_get_contents($hook);

    expect($contents)
        ->toContain('load-testing/results/')
        ->toContain('storage/app/load-tests/')
        ->toContain('load-testing/results/consolidated/')
        ->toContain('COMMIT REFUSED')
        // The hook must be a no-op on a clean index, or it blocks every commit.
        ->toContain('git diff --cached --name-only');
});

it('tracks no files from the ignored artifact trees', function (): void {
    // storage/app/load-tests/ has never legitimately held tracked files, so it
    // must stay empty. Under load-testing/results/, only the consolidated data
    // files may be tracked; the legacy battery reports that predate the rule
    // are left to history.
    $tracked = loadTestHygieneGit(['ls-files', 'storage/app/load-tests']);

    expect(trim($tracked->getOutput()))->toBe(
        '',
        'storage/app/load-tests must not contain tracked files',
    );
});
