<?php

declare(strict_types=1);

namespace Modules\Provision\Services;

use Modules\Provision\Models\ProvisionTemplate;

/**
 * Service interface for device provisioning: template management and
 * rendering device configuration from stored templates.
 */
interface ProvisionServiceInterface
{
    /**
     * Create a new provisioning template.
     */
    public function createTemplate(array $data): ProvisionTemplate;

    /**
     * Update an existing provisioning template and return the fresh copy.
     */
    public function updateTemplate(ProvisionTemplate $template, array $data): ProvisionTemplate;

    /**
     * Delete a provisioning template.
     */
    public function deleteTemplate(ProvisionTemplate $template): void;

    /**
     * Register a vendor template file path for a specific phone model.
     */
    public function registerTemplate(string $vendor, string $model, string $path): void;

    /**
     * Resolve the best-matching template path for a vendor and model.
     */
    public function resolveTemplate(string $vendor, ?string $model): ?string;

    /**
     * Render the provisioning configuration for a device by MAC address.
     */
    public function provision(string $mac): array;
}
