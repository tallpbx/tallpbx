<?php

declare(strict_types=1);

namespace Modules\PinNumbers\Support;

use App\Support\ModuleTableUninstaller;

/**
 * Destructively removes the PIN numbers module schema.
 */
class PinNumbersUninstaller extends ModuleTableUninstaller
{
    /**
     * Return the module registry name this uninstaller owns.
     */
    public function moduleName(): string
    {
        return 'pin-numbers';
    }

    /**
     * Return the module-owned table names.
     *
     * @return array<int, string>
     */
    protected function tables(): array
    {
        return ['pin_numbers'];
    }

    /**
     * Return migration names that create the module-owned tables.
     *
     * @return array<int, string>
     */
    protected function migrations(): array
    {
        return ['2026_07_06_000001_create_pin_numbers_table'];
    }
}
