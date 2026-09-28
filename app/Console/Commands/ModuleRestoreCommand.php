<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ModuleLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Restore a previously uninstalled module from its origin.
 *
 * First-party modules return from the git repository (including their
 * central tests); vendor modules are reinstalled through Composer.
 * Migrations re-run into empty tables and permissions are re-seeded.
 * Database data is not restored.
 */
class ModuleRestoreCommand extends Command
{
    protected $signature = 'module:restore
        {name : Module name in kebab-case (e.g., "call-broadcast")}';

    protected $description = 'Restore a previously uninstalled module from git or Composer';

    /**
     * Execute the console command.
     *
     * Prints the restore plan and hands the work to ModuleLifecycleService.
     */
    public function handle(ModuleLifecycleService $lifecycle): int
    {
        $name = (string) $this->argument('name');
        $preview = $lifecycle->previewRestore($name);

        if (! $preview['can_restore']) {
            $this->components->error($preview['reason'] ?? 'This module cannot be restored.');

            return self::FAILURE;
        }

        $this->components->info('Restore plan:');

        foreach ($preview['items'] as $item) {
            $this->line('  - '.$item);
        }

        $this->components->warn($preview['data_notice']);

        try {
            $report = $lifecycle->restore($name);
        } catch (ValidationException $exception) {
            $this->components->error(collect($exception->errors())->flatten()->first() ?? 'The module could not be restored.');

            return self::FAILURE;
        }

        $this->components->info("Restored module [{$name}] from {$report['source']}.");

        return self::SUCCESS;
    }
}
