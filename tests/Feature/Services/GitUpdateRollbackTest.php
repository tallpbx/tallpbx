<?php

declare(strict_types=1);

use App\Contracts\ProcessRunner;
use App\Services\GitUpdateService;
use App\Support\ProcessResult;

/*
 * A failed update rolls the code back and rebuilds assets. The rollback must
 * reinstall Node dependencies from the restored lockfile first; building
 * against the failed version's node_modules can silently ship the wrong
 * front-end bundle.
 */

it('reinstalls Node dependencies from the restored lockfile before rebuilding', function (): void {
    $runner = new class implements ProcessRunner
    {
        /** @var list<array<int, string>> */
        public array $commands = [];

        /**
         * Record the command and fail the build step.
         */
        public function run(array $argv, ?string $cwd = null, ?int $timeoutSeconds = null, array $env = []): ProcessResult
        {
            $this->commands[] = $argv;

            // Simulate an update that failed after composer/npm installs.
            if (in_array('build', $argv, true)) {
                return new ProcessResult(1, 'build failed');
            }

            return new ProcessResult(0, '');
        }
    };

    $service = new GitUpdateService($runner);
    $steps = [];

    $method = new ReflectionMethod($service, 'rollback');
    $method->invoke($service, '5.x', 'abc1234', $steps, 'NPM build failed', '');

    $commands = $runner->commands;
    $ciIndex = array_search(['npm', 'ci', '--ignore-scripts'], $commands, true);
    $buildIndex = array_search(['npm', 'run', 'build'], $commands, true);

    expect($ciIndex)->not->toBeFalse()
        ->and($buildIndex)->not->toBeFalse()
        ->and($ciIndex)->toBeLessThan($buildIndex);
});
