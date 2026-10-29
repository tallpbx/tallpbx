<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Services\PermissionService;

/**
 * Handles registration of module permissions with PermissionService.
 */
class ModulePermissionRegistrar
{
    public function __construct(private readonly ?PermissionService $permissionService = null) {}

    /**
     * Register permissions for a module.
     *
     * @param  array<string, mixed>  $permissions
     */
    public function registerForModule(string $moduleName, array $permissions): void
    {
        if ($permissions !== []) {
            $service = $this->permissionService ?? app(PermissionService::class);
            $service->register($moduleName, $permissions);
        }
    }
}
