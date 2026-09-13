<?php

declare(strict_types=1);

namespace Modules\Destinations\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Destinations\Models\Destination;

/**
 * Service implementation for managing destinations.
 */
class DestinationService implements DestinationServiceInterface
{
    /**
     * Create a new destination.
     */
    public function create(array $data): Destination
    {
        return Destination::create($data);
    }

    /**
     * Update an existing destination.
     */
    public function update(Destination $destination, array $data): Destination
    {
        $destination->update($data);

        return $destination->fresh();
    }

    /**
     * Delete a destination.
     */
    public function delete(Destination $destination): void
    {
        $destination->delete();
    }

    /**
     * Get all destinations for a tenant.
     */
    public function getByTenant(int $tenantId): Collection
    {
        return Destination::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->get();
    }

    /**
     * Get destinations for a tenant filtered by type.
     */
    public function getByType(int $tenantId, string $type): Collection
    {
        return Destination::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
            ->where('type', $type)
            ->get();
    }
}
