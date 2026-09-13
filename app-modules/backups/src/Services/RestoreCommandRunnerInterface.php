<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

/**
 * Runs a bounded command required by the root-owned restore executor.
 */
interface RestoreCommandRunnerInterface
{
    /**
     * Run an argument-safe command and return its standard output.
     *
     * @param  list<string>  $command
     */
    public function run(array $command, ?string $input = null, array $environment = []): string;
}
