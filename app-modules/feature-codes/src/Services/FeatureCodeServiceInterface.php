<?php

declare(strict_types=1);

namespace Modules\FeatureCodes\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\FeatureCodes\Models\FeatureCode;

/**
 * Service for managing tenant feature codes (star codes).
 */
interface FeatureCodeServiceInterface
{
    /**
     * Create a new feature code.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): FeatureCode;

    /**
     * Update an existing feature code.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FeatureCode $code, array $data): FeatureCode;

    /**
     * Delete a feature code.
     */
    public function delete(FeatureCode $code): void;

    /**
     * Get all feature codes for a specific tenant.
     *
     * @return Collection<int, FeatureCode>
     */
    public function getByTenant(int $tenantId): Collection;
}
