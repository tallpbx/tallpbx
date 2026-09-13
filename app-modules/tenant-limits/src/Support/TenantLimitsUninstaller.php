<?php

declare(strict_types=1);

namespace Modules\TenantLimits\Support;

use App\Support\ModuleTableUninstaller;

/**
 * Removes database schema owned exclusively by the tenant-limits module.
 */
class TenantLimitsUninstaller extends ModuleTableUninstaller
{
    /**
     * Return the registry name for the tenant-limits module.
     */
    public function moduleName(): string
    {
        return 'tenant-limits';
    }

    /**
     * Return the table owned by the tenant-limits module.
     *
     * @return array<int, string>
     */
    protected function tables(): array
    {
        return ['tenant_limits'];
    }

    /**
     * Return the module migration that recreates the tenant limits table.
     *
     * @return array<int, string>
     */
    protected function migrations(): array
    {
        return ['2026_07_06_000016_create_tenant_limits_table'];
    }
}
