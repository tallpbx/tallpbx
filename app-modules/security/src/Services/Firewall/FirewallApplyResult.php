<?php

declare(strict_types=1);

namespace Modules\Security\Services\Firewall;

/**
 * Value object representing the outcome of a firewall ruleset apply operation.
 */
class FirewallApplyResult
{
    /**
     * Create a new firewall apply result value object.
     *
     * @param  bool  $successful  Whether the ruleset was successfully validated and applied
     * @param  string|null  $errorMessage  Failure explanation if the operation was rejected or aborted
     * @param  string|null  $stage  The pipeline stage where the failure occurred (lockout_guard, generator, syntax_validation, executor)
     * @param  string|null  $pendingFile  Path to the written pending configuration file (if reached)
     * @param  bool  $auditLogged  Whether an audit log record was successfully committed
     * @param  bool  $broadcast  Whether a real-time ruleset update event was broadcast
     */
    public function __construct(
        public readonly bool $successful,
        public readonly ?string $errorMessage = null,
        public readonly ?string $stage = null,
        public readonly ?string $pendingFile = null,
        public readonly bool $auditLogged = false,
        public readonly bool $broadcast = false,
    ) {}

    /**
     * Construct a successful apply result.
     */
    public static function success(
        ?string $pendingFile = null,
        bool $auditLogged = true,
        bool $broadcast = true,
    ): self {
        return new self(
            successful: true,
            errorMessage: null,
            stage: null,
            pendingFile: $pendingFile,
            auditLogged: $auditLogged,
            broadcast: $broadcast,
        );
    }

    /**
     * Construct a failed apply result.
     */
    public static function failure(
        string $errorMessage,
        string $stage,
        ?string $pendingFile = null,
    ): self {
        return new self(
            successful: false,
            errorMessage: $errorMessage,
            stage: $stage,
            pendingFile: $pendingFile,
            auditLogged: false,
            broadcast: false,
        );
    }

    /**
     * Determine whether the apply operation failed.
     */
    public function failed(): bool
    {
        return ! $this->successful;
    }
}
