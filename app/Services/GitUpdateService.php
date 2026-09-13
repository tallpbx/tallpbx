<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessRunner;
use App\Support\UpdateResult;
use Illuminate\Support\Facades\Log;

/**
 * Provides safe, read-only Git operations for the admin update UI.
 *
 * All commands are restricted to the application's base path and never
 * accept unsanitized user input. Branch and tag names are validated
 * against the remote's actual list before use.
 *
 * The update workflow:
 *   1. isClean() — verify no uncommitted changes
 *   2. fetch() — pull latest refs from remote
 *   3. remoteBranches() / remoteTags() — show available targets
 *   4. pull(target) — fast-forward to the selected branch/tag
 */
class GitUpdateService
{
    /**
     * The application root directory where .git lives.
     */
    private string $repoPath;

    public function __construct(private readonly ProcessRunner $runner)
    {
        $this->repoPath = base_path();
    }

    /**
     * Get the URL of the origin remote.
     */
    public function remoteUrl(): string
    {
        $output = $this->runGitCommand('remote get-url origin', $exitCode);

        if ($exitCode !== 0) {
            return '';
        }

        return trim($output);
    }

    /**
     * Get the currently checked-out branch name.
     */
    public function currentBranch(): string
    {
        $output = $this->runGitCommand('rev-parse --abbrev-ref HEAD', $exitCode);

        if ($exitCode !== 0) {
            return '';
        }

        return trim($output);
    }

    /**
     * Get the friendly version description or short commit hash.
     */
    public function currentVersion(): string
    {
        $output = $this->runGitCommand('describe --tags --always', $exitCode);

        if ($exitCode !== 0) {
            return '';
        }

        return trim($output);
    }

    /**
     * Categorize branches into stable releases, development, and other branches.
     *
     * Following FusionPBX release conventions:
     * - Stable: version branches like 1.0, 1.1, 5.3, v1.0, stable, release/*
     * - Development: main, master, dev, develop
     * - Other: any feature or custom branches
     *
     * @param  array<int, string>  $branches
     * @return array{stable: array<int, string>, development: array<int, string>, other: array<int, string>}
     */
    public function categorizeBranches(array $branches): array
    {
        $stable = [];
        $development = [];
        $other = [];

        foreach ($branches as $branch) {
            $lower = strtolower($branch);
            if (in_array($lower, ['main', 'master', 'dev', 'develop'], true)) {
                $development[] = $branch;
            } elseif (
                $lower === 'stable'
                || str_starts_with($lower, 'release/')
                || preg_match('/^v?\d+(\.\d+)+(-stable)?$/i', $branch) === 1
            ) {
                $stable[] = $branch;
            } else {
                $other[] = $branch;
            }
        }

        // Sort stable branches in reverse version order so latest is first
        usort($stable, function (string $a, string $b): int {
            if ($a === 'stable') {
                return -1;
            }
            if ($b === 'stable') {
                return 1;
            }

            return version_compare(ltrim($b, 'v'), ltrim($a, 'v'));
        });

        return [
            'stable' => array_values($stable),
            'development' => array_values($development),
            'other' => array_values($other),
        ];
    }

    /**
     * Count how many commits the current HEAD is behind the remote target.
     */
    public function commitsBehind(string $target): int
    {
        $output = $this->runGitCommand('rev-list --count HEAD..origin/'.escapeshellarg($target), $exitCode);

        if ($exitCode !== 0) {
            return 0;
        }

        return (int) trim($output);
    }

    /**
     * Get recent incoming commit details for the target branch.
     *
     * @return array<int, array{hash: string, author: string, time: string, message: string}>
     */
    public function incomingCommits(string $target, int $limit = 5): array
    {
        $output = $this->runGitCommand('log HEAD..origin/'.escapeshellarg($target).' -n '.$limit.' --format=%h|%an|%ar|%s', $exitCode);

        if ($exitCode !== 0 || trim($output) === '') {
            return [];
        }

        $commits = [];
        foreach (explode("\n", trim($output)) as $line) {
            $parts = explode('|', $line, 4);
            if (count($parts) === 4) {
                $commits[] = [
                    'hash' => $parts[0],
                    'author' => $parts[1],
                    'time' => $parts[2],
                    'message' => $parts[3],
                ];
            }
        }

        return $commits;
    }

    /**
     * Check whether the working tree is clean (no uncommitted changes).
     */
    public function isClean(): bool
    {
        $output = $this->runGitCommand('status --porcelain', $exitCode);

        if ($exitCode !== 0) {
            return false;
        }

        return trim($output) === '';
    }

    /**
     * Fetch the latest refs from the origin remote.
     */
    public function fetch(): bool
    {
        $output = $this->runGitCommand('fetch origin --prune --quiet', $exitCode);

        return $exitCode === 0;
    }

    /**
     * Pull or switch to the specified branch or tag.
     *
     * Uses --ff-only when on the same branch, or checkout -B when switching branches.
     */
    public function pull(string $target): bool
    {
        // Validate: target must be a known branch or tag from the remote
        $validTargets = array_merge($this->remoteBranches(), $this->remoteTags());

        if (! in_array($target, $validTargets, true)) {
            return false;
        }

        $current = $this->currentBranch();
        if ($current !== '' && $current !== $target) {
            $this->runGitCommand('checkout -B '.escapeshellarg($target).' '.escapeshellarg('origin/'.$target), $exitCode);
        } else {
            // Use --ff-only for safe fast-forward (no merge commits)
            $this->runGitCommand('pull --ff-only origin '.escapeshellarg($target), $exitCode);
        }

        return $exitCode === 0;
    }

    /**
     * List remote branch names (stripped of 'origin/' prefix).
     *
     * @return array<int, string>
     */
    public function remoteBranches(): array
    {
        $output = $this->runGitCommand('branch -r --format="%(refname:short)"', $exitCode);

        if ($exitCode !== 0 || $output === '') {
            return [];
        }

        $branches = explode("\n", trim($output));

        // Strip 'origin/' prefix from each branch name
        return array_values(array_filter(array_map(function (string $b): string {
            if (str_starts_with($b, 'origin/')) {
                $b = substr($b, 7);
            }

            // Skip HEAD / origin symref references
            if ($b === 'origin' || $b === 'HEAD' || str_contains($b, '->')) {
                return '';
            }

            return $b;
        }, $branches)));
    }

    /**
     * List remote tag names.
     *
     * @return array<int, string>
     */
    public function remoteTags(): array
    {
        $output = $this->runGitCommand('tag -l --sort=-version:refname', $exitCode);

        if ($exitCode !== 0 || $output === '') {
            return [];
        }

        return array_values(array_filter(explode("\n", trim($output))));
    }

    /**
     * Run the full update pipeline for a target branch or tag.
     *
     * Preflight (clean tree, lockfiles, valid target) → pull (--ff-only) →
     * composer install → migrate → npm ci + build → permissions repair →
     * optimize clear. Each step is logged in real time to the progress log.
     * Failure after the pull triggers the code+assets rollback.
     * An atomic lock prevents concurrent pipelines.
     */
    public function update(string $target): UpdateResult
    {
        $steps = [];

        $this->writeStatus([
            'running' => true,
            'target' => $target,
            'current_step' => 'Preflight',
            'steps' => [],
            'success' => null,
            'reason' => null,
            'rollback_report' => null,
            'started_at' => time(),
        ]);
        $this->appendLog("=== TallPBX Update started for target '{$target}' at ".date('Y-m-d H:i:s').' ===');

        // Atomic lock: two admins or two tabs must never interleave
        // composer/npm/migrate writes. The lock spans the whole pipeline
        // and is taken before anything else runs.
        $lock = fopen(storage_path('framework/git-update.lock'), 'c');

        if ($lock === false) {
            Log::error('Could not open the git update lock file.', [
                'path' => storage_path('framework/git-update.lock'),
            ]);
            $this->addStep($steps, 'Preflight', 'failed', 'Update lock unavailable.');

            $result = UpdateResult::failed($target, 'Update lock unavailable.', $steps);
            $this->recordFinalStatus($result);

            return $result;
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->addStep($steps, 'Preflight', 'failed', 'Another update is already in progress.');

            $result = UpdateResult::failed($target, 'Another update is already in progress.', $steps);
            $this->recordFinalStatus($result);

            return $result;
        }

        try {
            $prePullHead = trim($this->runGitCommand('rev-parse HEAD'));
            $preSwitchBranch = $this->currentBranch();

            $result = $this->runUpdate($target, $prePullHead, $steps, $preSwitchBranch);
            $this->recordFinalStatus($result);

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Record the final result into the status file and progress log.
     */
    private function recordFinalStatus(UpdateResult $result): void
    {
        $this->writeStatus([
            'running' => false,
            'target' => $result->target,
            'current_step' => null,
            'steps' => $result->steps,
            'success' => $result->success,
            'reason' => $result->reason,
            'rollback_report' => $result->rollbackReport,
            'completed_at' => time(),
        ]);

        $statusStr = $result->success ? 'SUCCEEDED' : 'FAILED';
        $this->appendLog("=== TallPBX Update {$statusStr} at ".date('Y-m-d H:i:s').' ===');
    }

    /**
     * Execute the preflight, pipeline, and rollback under the update lock.
     *
     * @param  array<int, array{label: string, status: string, output: string}>  $steps
     */
    private function runUpdate(string $target, string $prePullHead, array $steps, string $preSwitchBranch = ''): UpdateResult
    {
        // Server-side re-verification — never trust the mount-time value.
        if (! $this->isClean()) {
            $this->addStep($steps, 'Preflight', 'failed', 'Repository has uncommitted changes.');

            return UpdateResult::failed($target, 'Repository has uncommitted changes. Please commit or stash them first.', $steps);
        }

        if (! in_array($target, array_merge($this->remoteBranches(), $this->remoteTags()), true)) {
            $this->addStep($steps, 'Preflight', 'failed', 'Target not a known remote branch or tag.');

            return UpdateResult::failed($target, 'The selected target is not a known remote branch or tag.', $steps);
        }

        if (! file_exists(base_path('composer.lock')) || ! file_exists(base_path('package-lock.json'))) {
            $this->addStep($steps, 'Preflight', 'failed', 'Missing dependency lockfile.');

            return UpdateResult::failed($target, 'Missing dependency lockfile. Cannot update safely.', $steps);
        }

        $validate = $this->runner->run('composer validate --no-check-publish');

        if (! $validate->ok()) {
            $this->addStep($steps, 'Preflight', 'failed', $validate->output);

            return UpdateResult::failed($target, 'composer.json validation failed: '.$validate->output, $steps);
        }

        $this->addStep($steps, 'Preflight', 'ok', 'Clean tree, lockfiles present, target valid.');

        try {
            return $this->runPipeline($target, $prePullHead, $steps, $preSwitchBranch);
        } catch (\Throwable $exception) {
            // Unexpected failures must never leave the app down with no
            // report: log and route into the rollback path.
            Log::error('Git update pipeline failed unexpectedly.', [
                'target' => $target,
                'error' => $exception->getMessage(),
            ]);

            try {
                return $this->rollback($target, $prePullHead, $steps, 'Unexpected failure: '.$exception->getMessage(), $preSwitchBranch);
            } catch (\Throwable $rollbackException) {
                // The rollback itself must not escape either, or the panel
                // would be stuck with no report.
                Log::error('Git update rollback failed unexpectedly.', [
                    'error' => $rollbackException->getMessage(),
                ]);

                return UpdateResult::failed(
                    $target,
                    'Unexpected failure: '.$exception->getMessage(),
                    $steps,
                    'Rollback itself failed. Please inspect logs and resolve manually.',
                );
            }
        }
    }

    /**
     * Execute the pull and post-pull steps, rolling back on failure.
     *
     * @param  array<int, array{label: string, status: string, output: string}>  $steps
     */
    private function runPipeline(string $target, string $prePullHead, array $steps, string $preSwitchBranch = ''): UpdateResult
    {
        $isSwitch = $preSwitchBranch !== '' && $preSwitchBranch !== $target;

        if ($isSwitch) {
            $gitCmd = 'git -C '.escapeshellarg($this->repoPath).' checkout -B '.escapeshellarg($target).' '.escapeshellarg('origin/'.$target);
            $gitLabel = 'Switch branch';
        } else {
            $gitCmd = 'git -C '.escapeshellarg($this->repoPath).' pull --ff-only origin '.escapeshellarg($target);
            $gitLabel = 'Pull';
        }

        $pull = $this->runner->run($gitCmd);
        $this->addStep($steps, $gitLabel, $pull->ok() ? 'ok' : 'failed', $pull->output);

        if (! $pull->ok()) {
            return UpdateResult::failed($target, $gitLabel.' failed.', $steps);
        }

        $permissionsScope = (function_exists('posix_geteuid') && posix_geteuid() === 0) ? 'full' : 'runtime';

        $sequence = [
            'Composer install' => 'composer install --no-interaction --prefer-dist',
            'Migrate' => $this->artisanCommand('migrate --force'),
            'NPM install' => 'npm ci --ignore-scripts',
            'NPM build' => 'npm run build',
            'Repair permissions' => $this->artisanCommand('permissions:repair --scope='.$permissionsScope),
            'Optimize clear' => $this->artisanCommand('optimize:clear'),
        ];

        foreach ($sequence as $label => $command) {
            $result = $this->runner->run($command);
            $this->addStep($steps, $label, $result->ok() ? 'ok' : 'failed', $result->output);

            if (! $result->ok()) {
                return $this->rollback($target, $prePullHead, $steps, "{$label} failed: {$result->output}", $preSwitchBranch);
            }
        }

        return UpdateResult::success($target, $steps);
    }

    /**
     * Roll the code and assets back to the pre-pull commit.
     *
     * The database is intentionally NOT rolled back (migrations are not
     * guaranteed reversible); the report tells the admin to reconcile the
     * schema manually.
     *
     * @param  array<int, array{label: string, status: string, output: string}>  $steps
     */
    private function rollback(string $target, string $prePullHead, array $steps, string $reason, string $preSwitchBranch = ''): UpdateResult
    {
        $rollbackSteps = [];

        if ($preSwitchBranch !== '' && $preSwitchBranch !== $target) {
            $rollbackSteps['Rollback branch'] = 'git -C '.escapeshellarg($this->repoPath).' checkout '.escapeshellarg($preSwitchBranch);
        }

        $permissionsScope = (function_exists('posix_geteuid') && posix_geteuid() === 0) ? 'full' : 'runtime';

        $rollbackSteps['Rollback git'] = 'git -C '.escapeshellarg($this->repoPath)." reset --hard {$prePullHead}";
        $rollbackSteps['Rollback composer'] = 'composer install --no-interaction --prefer-dist';
        $rollbackSteps['Rollback build'] = 'npm run build';
        $rollbackSteps['Rollback repair'] = $this->artisanCommand('permissions:repair --scope='.$permissionsScope);
        $rollbackSteps['Rollback clear'] = $this->artisanCommand('optimize:clear');

        foreach ($rollbackSteps as $label => $command) {
            $result = $this->runner->run($command);
            $this->addStep($steps, $label, $result->ok() ? 'ok' : 'failed', $result->output);

            if (! $result->ok()) {
                return UpdateResult::failed(
                    $target,
                    $reason,
                    $steps,
                    "Rollback failed at {$label}. Please inspect logs and resolve manually.",
                );
            }
        }

        return UpdateResult::failed($target, $reason, $steps, 'Rolled back to '.$prePullHead.'. The database schema may need manual reconciliation.');
    }

    /**
     * Return the path to the update status file.
     */
    public function statusFilePath(): string
    {
        return storage_path('framework/git-update-status.json');
    }

    /**
     * Return the path to the live update log file.
     */
    public function logFilePath(): string
    {
        return storage_path('framework/git-update.log');
    }

    /**
     * Get the latest update status array from storage.
     *
     * @return array{running: bool, target: string, current_step: ?string, steps: array, success: ?bool, reason: ?string, rollback_report: ?string, started_at?: int, completed_at?: int}|null
     */
    public function getStatus(): ?array
    {
        $path = $this->statusFilePath();
        if (! file_exists($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Get the recent terminal log lines.
     */
    public function getLog(int $maxLines = 250): string
    {
        $path = $this->logFilePath();
        if (! file_exists($path)) {
            return '';
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false || empty($lines)) {
            return '';
        }

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return implode("\n", $lines);
    }

    /**
     * Check whether an update is currently running.
     */
    public function isUpdating(): bool
    {
        $status = $this->getStatus();

        return ($status['running'] ?? false) === true;
    }

    /**
     * Write state into the JSON status file.
     *
     * @param  array<string, mixed>  $data
     */
    public function writeStatus(array $data): void
    {
        @file_put_contents($this->statusFilePath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Append a line or block of text to the update log file.
     */
    public function appendLog(string $message): void
    {
        @file_put_contents($this->logFilePath(), trim($message)."\n", FILE_APPEND);
    }

    /**
     * Launch the update pipeline asynchronously in the background.
     */
    public function startBackgroundUpdate(string $target): void
    {
        $cli = $this->phpBinary();
        $artisan = base_path('artisan');
        $logPath = $this->logFilePath();

        // Write initial status immediately so UI polling detects the start right away
        $this->writeStatus([
            'running' => true,
            'target' => $target,
            'current_step' => 'Starting update...',
            'steps' => [],
            'success' => null,
            'reason' => null,
            'rollback_report' => null,
            'started_at' => time(),
        ]);

        @file_put_contents($logPath, "=== TallPBX Update started for target '{$target}' at ".date('Y-m-d H:i:s')." ===\n");

        $cmd = sprintf(
            'nohup %s %s app:git-update %s >> %s 2>&1 &',
            escapeshellarg($cli),
            escapeshellarg($artisan),
            escapeshellarg($target),
            escapeshellarg($logPath),
        );

        exec($cmd);
    }

    /**
     * Resolve the CLI PHP binary path, even when running under PHP-FPM.
     */
    public function phpBinary(): string
    {
        $binary = PHP_BINARY;

        if (str_contains($binary, 'fpm')) {
            $versionedCli = PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
            if (file_exists($versionedCli)) {
                return $versionedCli;
            }

            $genericCli = PHP_BINDIR.'/php';
            if (file_exists($genericCli)) {
                return $genericCli;
            }

            return '/usr/bin/php';
        }

        return $binary;
    }

    /**
     * Build an artisan command for the CLI PHP binary.
     */
    private function artisanCommand(string $arguments): string
    {
        return escapeshellarg($this->phpBinary()).' '.escapeshellarg(base_path('artisan')).' '.$arguments;
    }

    /**
     * Append a step to the pipeline log.
     *
     * @param  array<int, array{label: string, status: string, output: string}>  $steps
     */
    private function addStep(array &$steps, string $label, string $status, string $output = ''): void
    {
        $steps[] = ['label' => $label, 'status' => $status, 'output' => trim($output)];

        $statusTag = $status === 'ok' ? 'OK' : ($status === 'failed' ? 'FAILED' : strtoupper($status));
        $this->appendLog('['.date('H:i:s')."] {$label}: {$statusTag}");

        if (trim($output) !== '') {
            $this->appendLog(trim($output));
        }

        $this->writeStatus([
            'running' => true,
            'target' => end($steps)['target'] ?? '',
            'current_step' => $label,
            'steps' => $steps,
            'success' => null,
            'reason' => null,
            'rollback_report' => null,
        ]);

        Log::info('Git update step.', ['label' => $label, 'status' => $status]);
    }

    /**
     * Execute a git command from within the repository root.
     *
     * @param  int|null  $exitCode  Filled with the command's exit code
     * @return string The command's stdout output
     */
    private function runGitCommand(string $command, ?int &$exitCode = null): string
    {
        $result = $this->runner->run('git -C '.escapeshellarg($this->repoPath).' '.$command);
        $exitCode = $result->exitCode;

        return $result->output;
    }
}
