<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\ProcessRunner;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Executes processes through Symfony Process from the application root or custom cwd.
 */
class SystemProcessRunner implements ProcessRunner
{
    /**
     * Run a process command under a per-step timeout.
     *
     * When a command times out, returns exit code 124 (matching GNU timeout semantics).
     *
     * @param  array<int, string>  $argv
     * @param  array<string, string>  $env
     */
    public function run(
        array $argv,
        ?string $cwd = null,
        ?int $timeoutSeconds = null,
        array $env = [],
    ): ProcessResult {
        $timeout = $timeoutSeconds ?? max(1, (int) config('git-update.step_timeout_seconds', 600));
        $workingDir = $cwd ?? base_path();

        // Ensure standard binary paths (/usr/local/bin for composer, npm, node) are in PATH
        // even when running under minimal environments like PHP-FPM.
        $currentPath = (string) (getenv('PATH') ?: ($_SERVER['PATH'] ?? ''));
        $paths = array_unique(array_filter(array_merge(
            ['/usr/local/bin', '/usr/bin', '/bin', '/usr/local/sbin', '/usr/sbin'],
            explode(':', $currentPath),
        )));
        $pathEnv = implode(':', $paths);

        // Ensure COMPOSER_HOME points to writable runtime storage for www-data
        $composerHome = storage_path('framework/composer');
        if (! is_dir($composerHome)) {
            @mkdir($composerHome, 0775, true);
        }

        $defaultEnv = [
            'PATH' => $pathEnv,
            'COMPOSER_HOME' => $composerHome,
            'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'GIT_SSH_COMMAND' => 'ssh -o StrictHostKeyChecking=accept-new',
        ];

        $mergedEnv = array_merge($defaultEnv, $env);

        $process = new Process(
            command: $argv,
            cwd: $workingDir,
            env: $mergedEnv,
            timeout: (float) $timeout,
        );

        try {
            $process->run();
            $exitCode = $process->getExitCode() ?? 1;
            $output = $process->getOutput().$process->getErrorOutput();
        } catch (ProcessTimedOutException $e) {
            return new ProcessResult(124, $process->getOutput().$process->getErrorOutput()."\nCommand timed out after {$timeout} seconds.");
        } catch (Throwable $e) {
            return new ProcessResult(1, $process->getOutput().$process->getErrorOutput()."\n".$e->getMessage());
        }

        return new ProcessResult($exitCode, $output);
    }
}
