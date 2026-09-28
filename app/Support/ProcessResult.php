<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The outcome of a single process run.
 */
readonly class ProcessResult
{
    /**
     * Create a result holding the exit code and captured output of a process run.
     */
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {}

    /**
     * Whether the command exited successfully.
     */
    public function ok(): bool
    {
        return $this->exitCode === 0;
    }
}
