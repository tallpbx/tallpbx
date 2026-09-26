<?php

declare(strict_types=1);

namespace Modules\Acl\Support;

use App\Support\ModuleTableUninstaller;

/**
 * Destructively removes ACL rules and nodes.
 */
class AclUninstaller extends ModuleTableUninstaller
{
    /**
     * Return the module registry name this uninstaller owns.
     */
    public function moduleName(): string
    {
        return 'acl';
    }

    /**
     * Return the module-owned table names in parent-to-child order.
     *
     * @return array<int, string>
     */
    protected function tables(): array
    {
        return ['access_controls', 'access_control_nodes'];
    }

    /**
     * Return migration names that create the module-owned tables.
     *
     * @return array<int, string>
     */
    protected function migrations(): array
    {
        return ['2026_07_01_000001_create_access_controls_table'];
    }
}
