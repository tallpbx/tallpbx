<?php

declare(strict_types=1);

namespace Modules\CallBroadcast\Support;

use App\Support\ModuleTableUninstaller;

/**
 * Removes database schema owned exclusively by the call-broadcast module.
 */
class CallBroadcastUninstaller extends ModuleTableUninstaller
{
    /**
     * Return the registry name for the call-broadcast module.
     */
    public function moduleName(): string
    {
        return 'call-broadcast';
    }

    /**
     * Return call-broadcast tables with the parent before its child.
     *
     * @return array<int, string>
     */
    protected function tables(): array
    {
        return ['call_broadcasts', 'call_broadcast_recipients'];
    }

    /**
     * Return the module migration that recreates both broadcast tables.
     *
     * @return array<int, string>
     */
    protected function migrations(): array
    {
        return ['2026_07_06_000011_create_call_broadcasts_table'];
    }
}
