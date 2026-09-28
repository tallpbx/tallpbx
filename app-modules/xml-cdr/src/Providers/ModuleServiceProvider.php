<?php

declare(strict_types=1);

namespace Modules\XmlCdr\Providers;

/**
 * Service provider for the xml-cdr module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'xml-cdr';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\XmlCdr';
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
                'permission' => 'xml-cdr.view',
                'icon' => 'heroicon-o-document-text',
                'parent' => 'pbx.monitoring',
                'guard' => 'admin',
                'order' => 10,
            ],
        ];
    }

    /**
     * Register permissions for the xml-cdr module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * Permission names MUST use the module name as their prefix
     * (xml-cdr.*): the Livewire action-permission resolver derives the
     * module slug from the component namespace, so a mismatched prefix
     * such as cdr.* would silently disable action authorization.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'xml-cdr.view' => 'View CDR records',
            'xml-cdr.delete' => 'Delete CDR records',
        ];
    }
}
