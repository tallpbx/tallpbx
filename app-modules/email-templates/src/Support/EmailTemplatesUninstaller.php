<?php

declare(strict_types=1);

namespace Modules\EmailTemplates\Support;

use App\Support\ModuleTableUninstaller;

/**
 * Destructively removes the email templates module schema.
 */
class EmailTemplatesUninstaller extends ModuleTableUninstaller
{
    /**
     * Return the module registry name this uninstaller owns.
     */
    public function moduleName(): string
    {
        return 'email-templates';
    }

    /**
     * Return the module-owned table names.
     *
     * @return array<int, string>
     */
    protected function tables(): array
    {
        return ['email_templates'];
    }

    /**
     * Return migration names that create the module-owned tables.
     *
     * @return array<int, string>
     */
    protected function migrations(): array
    {
        return ['2026_07_06_000018_create_email_templates_table'];
    }
}
