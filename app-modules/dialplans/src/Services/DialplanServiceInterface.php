<?php

declare(strict_types=1);

namespace Modules\Dialplans\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Dialplans\Models\Dialplan;

/**
 * Service for managing call routing dialplans with ordered conditions.
 */
interface DialplanServiceInterface
{
    /**
     * Create a new dialplan, optionally with details.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Dialplan;

    /**
     * Update an existing dialplan.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Dialplan $dialplan, array $data): Dialplan;

    /**
     * Delete a dialplan along with its details.
     */
    public function delete(Dialplan $dialplan): void;

    /**
     * Get all dialplans for a specific tenant.
     *
     * @return Collection<int, Dialplan>
     */
    public function getByTenant(int $tenantId): Collection;

    /**
     * Generate FreeSWITCH XML configuration for a dialplan.
     */
    public function generateConfig(Dialplan $dialplan): string;
}
