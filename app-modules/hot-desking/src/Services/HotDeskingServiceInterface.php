<?php

declare(strict_types=1);

namespace Modules\HotDesking\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\HotDesking\Models\HotDeskSession;

/**
 * Service contract for Hot Desking session management.
 */
interface HotDeskingServiceInterface
{
    /**
     * Start a hot desking session linking a user extension to a physical desk phone extension.
     */
    public function login(int $tenantId, string $extensionId, string $deviceExtensionId, ?string $ipAddress = null, ?string $description = null): HotDeskSession;

    /**
     * Terminate any active hot desking session for the given extension.
     */
    public function logout(int $tenantId, string $extensionId): bool;

    /**
     * End a specific hot desking session.
     */
    public function endSession(HotDeskSession $session): bool;

    /**
     * Retrieve all active hot desking sessions for a tenant.
     *
     * @return Collection<int, HotDeskSession>
     */
    public function getActiveSessions(int $tenantId): Collection;

    /**
     * Find active session for a visiting user extension.
     */
    public function getActiveSessionForExtension(int $tenantId, string $extensionId): ?HotDeskSession;

    /**
     * Find active session currently occupying a desk phone extension.
     */
    public function getActiveSessionForDeviceExtension(int $tenantId, string $deviceExtensionId): ?HotDeskSession;
}
