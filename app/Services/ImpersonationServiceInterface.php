<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ImpersonationException;
use App\Models\Admin;
use App\Models\User;

/**
 * Contract for admin impersonation operations.
 *
 * Provides methods to begin, end, and verify admin impersonation of user accounts. Used for troubleshooting and support scenarios.
 */
interface ImpersonationServiceInterface
{
    /**
     * Begin impersonating the given user as the current admin.
     *
     * @throws ImpersonationException if the admin lacks permission
     *                                or the target is not a tenant user.
     */
    public function impersonate(User $user): void;

    /**
     * End the current impersonation session and restore the original admin.
     *
     * @throws ImpersonationException if no impersonation is active.
     */
    public function stop(): void;

    /**
     * Check whether the current session is an impersonation.
     */
    public function isImpersonating(): bool;

    /**
     * Get the original admin who initiated the current impersonation, or null.
     */
    public function getOriginalAdmin(): ?Admin;
}
