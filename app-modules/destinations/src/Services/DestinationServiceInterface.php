<?php

declare(strict_types=1);

namespace Modules\Destinations\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Destinations\Models\Destination;

/**
 * Service for managing named call destinations (conferences, IVRs, etc.).
 */
interface DestinationServiceInterface
{
    /**
     * Create a new destination.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Destination;

    /**
     * Update an existing destination.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Destination $destination, array $data): Destination;

    /**
     * Delete a destination.
     */
    public function delete(Destination $destination): void;

    /**
     * Get all destinations for a specific tenant.
     *
     * @return Collection<int, Destination>
     */
    public function getByTenant(int $tenantId): Collection;

    /**
     * Get destinations filtered by type for a specific tenant.
     *
     * @return Collection<int, Destination>
     */
    public function getByType(int $tenantId, string $type): Collection;
}
