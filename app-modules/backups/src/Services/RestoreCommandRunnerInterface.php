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
     * @param  string|resource|null  $input  A stream is forwarded to the process
     *                                       unchanged; `resource` is not a legal
     *                                       native parameter type.
     */
    public function run(array $command, mixed $input = null, array $environment = []): string;
}
