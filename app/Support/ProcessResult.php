<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The outcome of a single process run.
 */
readonly class ProcessResult
{
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
