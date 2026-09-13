<?php

declare(strict_types=1);

namespace Modules\Extensions\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Extensions\Models\Extension;

/**
 * Service for managing tenant-local phone extensions.
 */
interface ExtensionServiceInterface
{
    /**
     * Create a new extension.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): Extension;

    /**
     * Update an existing extension.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Extension $extension, array $data): Extension;

    /**
     * Create a contiguous numeric extension range.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Extension>
     *
     * @throws ValidationException
     */
    public function createRange(array $data, int $start, int $end, int $increment = 1, ?string $displayNameTemplate = null): Collection;

    /**
     * Replace the portal users assigned to an extension.
     *
     * @param  array<int, int>  $userIds
     */
    public function syncUsers(Extension $extension, array $userIds): Extension;

    /**
     * Delete an extension.
     */
    public function delete(Extension $extension): void;

    /**
     * Get all extensions for a specific tenant.
     *
     * @return Collection<int, Extension>
     */
    public function getByTenant(int $tenantId): Collection;
}
