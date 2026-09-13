<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Module;
use Illuminate\Console\Command;

/**
 * List modules known to the TallPBX module registry.
 */
class ModuleListCommand extends Command
{
    protected $signature = 'module:list';

    protected $description = 'List modules synchronized into the TallPBX registry';

    /**
     * Display registered modules with lifecycle status.
     */
    public function handle(): int
    {
        $modules = Module::query()
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        if ($modules->isEmpty()) {
            $this->components->warn('No modules found. Run php artisan module:sync first.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Display Name', 'Version', 'Status', 'Required', 'Protected'],
            $modules->map(fn (Module $module): array => [
                $module->name,
                $module->display_name,
                $module->version,
                $module->status,
                $module->required ? 'yes' : 'no',
                $module->protected ? 'yes' : 'no',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
