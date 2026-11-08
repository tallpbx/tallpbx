<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Load-Test Artifact Hygiene
|--------------------------------------------------------------------------
|
| Raw run directories are working data, never repository content
| (load-testing/README.md rule 6). Three independent guards keep them out:
|
|   1. `.gitignore` keeps them unstaged by accident.
|   2. `scripts/git-hooks/pre-commit` refuses a commit that stages them.
|   3. This test fails the suite if either guard is removed.
|
| The rule exists because one series produces thousands of per-cell files
| (SIPp traces, CSVs, JSON reports, transport logs) that bloat every clone
| and are unreviewable. The durable record of a series is its report in
| docs/, not the run directory.
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

it('ignores both raw artifact trees', function (): void {
    $samples = [
        'load-testing/results/demo-series/h1-1c1g/sip-cps5/sipp.log',
        'storage/app/load-tests/demo-series/sipp-users.csv',
    ];

    foreach ($samples as $path) {
        $process = loadTestHygieneGit(['check-ignore', '-q', $path]);

        expect($process->getExitCode())->toBe(
            0,
            "{$path} must be git-ignored — raw run directories are never committed",
        );
    }
});

it('ships an executable pre-commit hook that refuses raw artifacts', function (): void {
    $hook = base_path('scripts/git-hooks/pre-commit');

    expect(is_file($hook))->toBeTrue('The pre-commit guard must exist')
        ->and(is_executable($hook))->toBeTrue('The guard must be executable');

    $contents = (string) file_get_contents($hook);

    expect($contents)
        ->toContain('load-testing/results/')
        ->toContain('storage/app/load-tests/')
        ->toContain('COMMIT REFUSED')
        // The hook must be a no-op on a clean index, or it blocks every commit.
        ->toContain('git diff --cached --name-only');
});

it('tracks no files from the ignored artifact trees', function (): void {
    // storage/app/load-tests/ has never legitimately held tracked files, so it
    // must stay empty. The legacy load-testing/results/ tree predates the rule
    // and is left to history; new series are refused by the ignore rule and the
    // hook, so they are not asserted here.
    $tracked = loadTestHygieneGit(['ls-files', 'storage/app/load-tests']);

    expect(trim($tracked->getOutput()))->toBe(
        '',
        'storage/app/load-tests must not contain tracked files',
    );
});
