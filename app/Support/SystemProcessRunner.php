<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\ProcessRunner;

/**
 * Executes commands through the system shell from the application root.
 */
class SystemProcessRunner implements ProcessRunner
{
    /**
     * Run a command from the application base path under a per-step timeout.
     *
     * GNU timeout kills a hung command with exit code 124, so a stuck step
     * fails the pipeline (rollback path) instead of blocking the request.
     */
    public function run(string $command): ProcessResult
    {
        $timeout = max(1, (int) config('git-update.step_timeout_seconds', 600));

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

        $gitSshCommand = 'ssh -o StrictHostKeyChecking=accept-new';

        // -k escalates to SIGKILL if the command ignores SIGTERM.
        $fullCommand = sprintf(
            'cd %s && PATH=%s COMPOSER_HOME=%s COMPOSER_NO_INTERACTION=1 COMPOSER_ALLOW_SUPERUSER=1 GIT_SSH_COMMAND=%s timeout -k 30s %ds %s 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg($pathEnv),
            escapeshellarg($composerHome),
            escapeshellarg($gitSshCommand),
            $timeout,
            $command,
        );

        exec($fullCommand, $output, $code);

        return new ProcessResult($code, implode("\n", $output));
    }
}
