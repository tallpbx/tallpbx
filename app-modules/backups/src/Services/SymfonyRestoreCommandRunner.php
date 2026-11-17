<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Executes root-helper restore commands without shell interpolation.
 */
class SymfonyRestoreCommandRunner implements RestoreCommandRunnerInterface, RestoreDeadlineAwareRunner
{
    private ?RestoreDeadline $deadline = null;

    /**
     * Create the runner with an optional per-command ceiling in seconds.
     */
    public function __construct(private readonly ?float $timeout = null) {}

    /**
     * Share the whole-operation deadline with every command in this runner.
     */
    public function setDeadline(RestoreDeadline $deadline): void
    {
        $this->deadline = $deadline;
    }

    /**
     * Run one command and raise its captured error output on failure.
     *
     * @param  list<string>  $command
     * @param  string|resource|null  $input  A stream is forwarded unchanged, so
     *                                       a dump is never loaded into memory.
     */
    public function run(array $command, mixed $input = null, array $environment = []): string
    {
        $process = new Process($command, base_path());
        $process->setInput($input);
        // A per-command ceiling only: Symfony's idle timer measures time since
        // the last console write, and dump/import commands produce no output
        // because they stream to a file, so an idle timeout would kill a
        // healthy dump that is making progress.
        $timeout = $this->timeout ?? (float) config('backup-storage.restore_command_timeout', 900.0);

        if ($this->deadline !== null) {
            $this->deadline->assertRemaining();
            $timeout = min($timeout, $this->deadline->remainingTimeoutSeconds());
        }

        $process->setTimeout($timeout);
        $process->run(env: $environment);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Restore helper command failed.');
        }

        return $process->getOutput();
    }
}
