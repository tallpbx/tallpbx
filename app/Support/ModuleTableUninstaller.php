<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\ModuleUninstaller;
use App\Models\Module;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base uninstaller for modules that own only database tables.
 */
abstract class ModuleTableUninstaller implements ModuleUninstaller
{
    /**
     * Determine whether the module can be uninstalled right now.
     */
    public function canUninstall(Module $module): bool
    {
        return true;
    }

    /**
     * Describe which tables and migration records will be removed.
     *
     * @return array<int, string>
     */
    public function previewUninstall(Module $module): array
    {
        return [
            'Drop module-owned table(s): '.implode(', ', $this->tables()),
            'Remove module migration record(s) so reinstall can recreate the table(s).',
        ];
    }

    /**
     * Drop module-owned tables and clear their migration records.
     */
    public function uninstall(Module $module): void
    {
        foreach ($this->dropTables() as $table) {
            Schema::dropIfExists($table);
        }

        $this->deleteMigrationRecords();
    }

    /**
     * Return module-owned table names in natural ownership order.
     *
     * @return array<int, string>
     */
    abstract protected function tables(): array;

    /**
     * Return module migration file basenames without the .php extension.
     *
     * @return array<int, string>
     */
    abstract protected function migrations(): array;

    /**
     * Return tables in safe drop order, with children before parents.
     *
     * @return array<int, string>
     */
    protected function dropTables(): array
    {
        return array_reverse($this->tables());
    }

    /**
     * Delete migration rows for this module so reinstall can run them again.
     */
    private function deleteMigrationRecords(): void
    {
        if (! Schema::hasTable('migrations')) {
            return;
        }

        DB::table('migrations')
            ->whereIn('migration', $this->migrations())
            ->delete();
    }
}
