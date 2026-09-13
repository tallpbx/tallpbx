<?php

declare(strict_types=1);

namespace Modules\InboundRoutes\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\InboundRoutes\Models\InboundRoute;

/**
 * Service interface for managing inbound routes.
 */
interface InboundRouteServiceInterface
{
    /**
     * Create a new inbound route.
     */
    public function create(array $data): InboundRoute;

    /**
     * Update an existing inbound route.
     */
    public function update(InboundRoute $route, array $data): InboundRoute;

    /**
     * Delete an inbound route.
     */
    public function delete(InboundRoute $route): void;

    /**
     * Get all inbound routes for a tenant.
     *
     * @return Collection<int, InboundRoute>
     */
    public function getByTenant(int $tenantId): Collection;
}
