<?php

declare(strict_types=1);

namespace Modules\EmailQueue\Support;

use App\Support\ModuleTableUninstaller;

/**
 * Removes database schema owned exclusively by the email-queue module.
 */
class EmailQueueUninstaller extends ModuleTableUninstaller
{
    /**
     * Return the registry name for the email-queue module.
     */
    public function moduleName(): string
    {
        return 'email-queue';
    }

    /**
     * Return the table owned by the email-queue module.
     *
     * @return array<int, string>
     */
    protected function tables(): array
    {
        return ['email_queue'];
    }

    /**
     * Return the module migration that recreates the email queue table.
     *
     * @return array<int, string>
     */
    protected function migrations(): array
    {
        return ['2026_07_06_000021_create_email_queue_table'];
    }
}
