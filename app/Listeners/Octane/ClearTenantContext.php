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
                $containers[spl_object_id($event->sandbox)] = $event->sandbox;
            }
            if (isset($event->app)) {
                $containers[spl_object_id($event->app)] = $event->app;
            }
        }

        $globalApp = app();
        $containers[spl_object_id($globalApp)] = $globalApp;

        foreach ($containers as $container) {
            if (method_exists($container, 'resolved') && $container->resolved(TenantManager::class)) {
                $container->make(TenantManager::class)->clear();
            }
        }
    }
}
