<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Executes root-helper restore commands without shell interpolation.
 */
class SymfonyRestoreCommandRunner implements RestoreCommandRunnerInterface
{
    /**
     * Run one command and raise its captured error output on failure.
     *
     * @param  list<string>  $command
     */
    public function run(array $command, ?string $input = null, array $environment = []): string
    {
        $process = new Process($command, base_path());
        $process->setInput($input);
        $process->run(env: $environment);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Restore helper command failed.');
        }

        return $process->getOutput();
    }
}
