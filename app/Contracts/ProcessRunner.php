<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\ProcessResult;

/**
 * Runs a process command using structured argument arrays and returns its exit code and output.
 *
 * The Git update pipeline runs every command through this runner so
 * tests can bind a fake and never execute real composer/npm/git.
 */
interface ProcessRunner
{
    /**
     * Execute a process command using an argument array.
     *
     * @param  array<int, string>  $argv
     * @param  array<string, string>  $env
     */
    public function run(
        array $argv,
        ?string $cwd = null,
        ?int $timeoutSeconds = null,
        array $env = [],
    ): ProcessResult;
}
