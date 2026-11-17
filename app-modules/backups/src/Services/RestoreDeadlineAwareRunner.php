<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

/**
 * Lets a restore command share the operation's absolute deadline.
 */
interface RestoreDeadlineAwareRunner
{
    /**
     * Bind the absolute operation budget before the first subprocess runs.
     */
    public function setDeadline(RestoreDeadline $deadline): void;
}
