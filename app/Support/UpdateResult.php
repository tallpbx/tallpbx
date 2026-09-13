<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The outcome of a Git update pipeline run.
 *
 * @param  array<int, array{label: string, status: string, output: string}>  $steps
 */
readonly class UpdateResult
{
    public function __construct(
        public bool $success,
        public string $target,
        public array $steps,
        public ?string $reason = null,
        public ?string $rollbackReport = null,
    ) {}

    /**
     * Build a successful result.
     */
    public static function success(string $target, array $steps): self
    {
        return new self(true, $target, $steps);
    }

    /**
     * Build a failed result, optionally with a rollback report.
     */
    public static function failed(string $target, string $reason, array $steps, ?string $rollbackReport = null): self
    {
        return new self(false, $target, $steps, $reason, $rollbackReport);
    }
}
