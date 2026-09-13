<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\ProcessResult;

/**
 * Runs a shell command and returns its exit code and output.
 *
 * The Git update pipeline runs every command through this runner so
 * tests can bind a fake and never execute real composer/npm/git.
 */
interface ProcessRunner
{
    /**
     * Execute a command in the application base path.
     */
    public function run(string $command): ProcessResult;
}
