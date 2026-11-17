<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Whole-operation time budget for a restore.
 *
 * The per-command ceiling in SymfonyRestoreCommandRunner bounds one hung
 * subprocess; this bounds a sequence of individually healthy commands. It is
 * created once per operation and never reset between phases. Subprocesses
 * share this budget, while systemd provides the hard stop for file copying.
 */
final class RestoreDeadline
{
    /** Absolute end of the budget. */
    private readonly Carbon $expiresAt;

    /**
     * Start the budget now.
     */
    public function __construct(int $seconds = 3600)
    {
        $this->expiresAt = Carbon::now()->addSeconds(max(1, $seconds));
    }

    /**
     * Seconds left before the budget is spent (never negative).
     */
    public function remainingSeconds(): int
    {
        return max(0, (int) ceil($this->remainingTimeoutSeconds()));
    }

    /**
     * Sub-second precision prevents a command from being granted time past
     * the operation deadline when less than one second remains.
     */
    public function remainingTimeoutSeconds(): float
    {
        return max(0.0, Carbon::now()->diffInSeconds($this->expiresAt, false));
    }

    /**
     * Refuse to continue when the budget is spent.
     *
     * @throws RuntimeException
     */
    public function assertRemaining(): void
    {
        if ($this->remainingTimeoutSeconds() <= 0) {
            throw new RuntimeException('The restore budget exhausted before the next phase could start.');
        }
    }

    /**
     * Absolute end of the budget, for journal metadata.
     */
    public function expiresAt(): Carbon
    {
        return $this->expiresAt;
    }
}
