<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Provides backward-compatible generated-file repair after Artisan commands.
 */
class GeneratedFilePermissions
{
    /**
     * Repair only generated files through the unified application permission service.
     */
    public static function repair(): void
    {
        app(ApplicationFilePermissions::class)->repair(PermissionRepairScope::Generated);
    }
}
