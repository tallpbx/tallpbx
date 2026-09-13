<?php

declare(strict_types=1);

namespace Modules\RingGroups\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\RingGroups\Models\RingGroup;

/**
 * Service interface for ring group CRUD operations.
 *
 * Provides methods to create, update, and delete ring groups
 * along with their associated extensions in a single transaction.
 */
interface RingGroupServiceInterface
{
    /**
     * Create a new ring group with its assigned extensions.
     *
     * @param  array<string, mixed>  $data  Ring group attributes.
     * @param  array<int, array<string, mixed>>  $extensions  Extension entries with extension_uuid and position.
     */
    public function create(array $data, array $extensions): RingGroup;

    /**
     * Update an existing ring group and sync its extensions.
     *
     * @param  array<string, mixed>  $data  Ring group attributes.
     * @param  array<int, array<string, mixed>>  $extensions  Updated extension entries.
     */
    public function update(RingGroup $group, array $data, array $extensions): RingGroup;

    /**
     * Delete a ring group and cascade-delete all associated extensions.
     */
    public function delete(RingGroup $group): void;

    /**
     * Get all ring groups ordered by name.
     *
     * @return Collection<int, RingGroup>
     */
    public function getAll(): Collection;
}
