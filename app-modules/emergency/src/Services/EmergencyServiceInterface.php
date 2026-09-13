<?php

declare(strict_types=1);

namespace Modules\Emergency\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Emergency\Models\Emergency;

/**
 * Contract for emergency (E911) configuration management.
 *
 * Provides full CRUD for per-tenant emergency settings
 * including caller ID, address, and geolocation data.
 * All operations internally handle tenant-scope bypass
 * where needed so callers never need to call
 * withoutGlobalScope('tenant') directly.
 */
interface EmergencyServiceInterface
{
    /**
     * Find an emergency configuration by ID, bypassing tenant scope for admin access.
     */
    public function find(string $id): Emergency;

    /**
     * Get all emergency configurations across all tenants, ordered by address.
     *
     * @return Collection<int, Emergency>
     */
    public function all(): Collection;

    /**
     * Create a new emergency configuration record.
     */
    public function create(array $data): Emergency;

    /**
     * Update an existing emergency configuration record.
     */
    public function update(Emergency $record, array $data): Emergency;

    /**
     * Delete an emergency configuration record.
     */
    public function delete(Emergency $record): void;
}
