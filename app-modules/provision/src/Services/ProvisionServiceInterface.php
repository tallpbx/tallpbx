<?php

declare(strict_types=1);

namespace Modules\Provision\Services;

use Modules\Provision\Models\ProvisionTemplate;

interface ProvisionServiceInterface
{
    public function createTemplate(array $data): ProvisionTemplate;

    public function updateTemplate(ProvisionTemplate $template, array $data): ProvisionTemplate;

    public function deleteTemplate(ProvisionTemplate $template): void;

    public function registerTemplate(string $vendor, string $model, string $path): void;

    public function resolveTemplate(string $vendor, ?string $model): ?string;

    public function provision(string $mac): array;
}
