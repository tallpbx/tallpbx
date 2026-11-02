<?php

declare(strict_types=1);

namespace App\Listeners\Octane;

use App\Services\TenantManager;

class ClearTenantContext
{
    /**
     * Handle the Octane event to reset the active tenant context.
     */
    public function handle(mixed $event = null): void
    {
        $containers = [];

        if (is_object($event)) {
            if (isset($event->sandbox)) {
                $containers[] = $event->sandbox;
            }
            if (isset($event->app)) {
                $containers[] = $event->app;
            }
        }

        $containers[] = app();

        foreach (array_unique($containers, SORT_REGULAR) as $container) {
            if ($container->bound(TenantManager::class)) {
                $container->make(TenantManager::class)->clear();
            }
        }
    }
}
