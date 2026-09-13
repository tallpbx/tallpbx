<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an impersonation operation cannot be completed.
 *
 * Reasons include: missing permission, attempting to chain
 * impersonation, or targeting a non-user entity.
 */
class ImpersonationException extends RuntimeException
{
    /**
     * Admin lacks the impersonate permission.
     */
    public static function unauthorized(): self
    {
        return new self('You do not have permission to impersonate users.');
    }

    /**
     * Already impersonating — chaining is not allowed.
     */
    public static function alreadyImpersonating(): self
    {
        return new self('Cannot impersonate while already impersonating another user.');
    }

    /**
     * The target is not a valid tenant user.
     */
    public static function notPortalUser(): self
    {
        return new self('Impersonation target must be a tenant user, not an admin.');
    }

    /**
     * No active impersonation session to stop.
     */
    public static function notImpersonating(): self
    {
        return new self('No active impersonation session to stop.');
    }

    /**
     * The original admin was disabled while impersonation was active.
     */
    public static function adminDisabled(): self
    {
        return new self('Your admin account has been disabled. Cannot restore admin session.');
    }
}
