<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Remove generated module cache files.
 *
 * Composer is responsible for module autoloading. This command only
 * clears first-party TallPBX module manifest caches.
 */
class ModuleClearCommand extends Command
{
    protected $signature = 'module:clear';

    protected $description = 'Remove generated module cache files';

    private const MODULES_CACHE = 'bootstrap/cache/modules.php';

    /**
     * Execute the console command.
     *
     * Removes modules.php and any stale legacy modules_autoload.php file.
     */
    public function handle(): int
    {
        $modulesCache = base_path(self::MODULES_CACHE);

        if (is_file($modulesCache)) {
            unlink($modulesCache);
            $this->components->task('Removed '.self::MODULES_CACHE);
        } else {
            $this->components->task('Skipped '.self::MODULES_CACHE.' (not found)');
        }

        $legacyAutoloadCache = base_path('bootstrap/cache/modules_autoload.php');

        if (is_file($legacyAutoloadCache)) {
            unlink($legacyAutoloadCache);
            $this->components->task('Removed legacy bootstrap/cache/modules_autoload.php');
        }

        $this->components->info('Module cache cleared.');

        return self::SUCCESS;
    }
}
