<?php

declare(strict_types=1);

namespace Modules\CallDetailRecords\Providers;

/**
 * Service provider for the call-detail-records module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'call-detail-records';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\CallDetailRecords';
    }

    /**
     * Register sidebar navigation menu items.
     *
     * Items with guard 'admin' appear in the admin sidebar.
     * Items with guard 'web' appear in the client/tenant sidebar.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function menuItems(): array
    {
        return [
            // Admin sidebar — visible to users with the 'admin' guard
            [
                'key' => 'admin.cdr',
                'label' => 'admin.cdr',
                'route' => 'panel.cdr.index',
                'permission' => 'call-detail-records.view',
                'icon' => 'heroicon-o-document-text',
                'parent' => 'pbx.monitoring',
                'guard' => 'admin',
                'order' => 10,
            ],
        ];
    }

    /**
     * Register permissions for the call-detail-records module.
     *
     * These permissions control access to viewing, exporting, and deleting
     * records. Exporting is separate from viewing because the CSV file leaves
     * the panel and lands on the operator's own disk, where the tenant's
     * access controls no longer apply.
     *
     * Permission names MUST use the module name as their prefix
     * (call-detail-records.*): the Livewire action-permission resolver derives
     * the module slug from the component namespace, so a shorter or renamed
     * prefix silently disables action authorization.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'call-detail-records.view' => 'View CDR records',
            'call-detail-records.export' => 'Export CDR records',
            'call-detail-records.delete' => 'Delete CDR records',
        ];
    }
}
