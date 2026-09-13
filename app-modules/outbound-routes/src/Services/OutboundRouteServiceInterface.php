<?php

declare(strict_types=1);

namespace Modules\OutboundRoutes\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\OutboundRoutes\Models\OutboundRoute;

/**
 * Service interface for managing outbound routes.
 */
interface OutboundRouteServiceInterface
{
    /**
     * Create a new outbound route.
     */
    public function create(array $data): OutboundRoute;

    /**
     * Update an existing outbound route.
     */
    public function update(OutboundRoute $route, array $data): OutboundRoute;

    /**
     * Delete an outbound route.
     */
    public function delete(OutboundRoute $route): void;

    /**
     * Get all outbound routes for a tenant.
     *
     * @return Collection<int, OutboundRoute>
     */
    public function getByTenant(int $tenantId): Collection;

    /**
     * Determine whether a dial pattern has at least one numbered capture group.
     */
    public function hasCapturingGroup(string $pattern): bool;

    /**
     * Validate a dial pattern as a PHP-compatible regular expression.
     */
    public function isValidDialPattern(string $pattern): bool;
}
