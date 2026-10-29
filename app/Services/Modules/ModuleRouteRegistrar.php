<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Services\ModuleCollisionGuard;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;

/**
 * Handles safe registration of module routes with collision guards.
 */
class ModuleRouteRegistrar
{
    public function __construct(private readonly ?ModuleCollisionGuard $guard = null) {}

    /**
     * Register routes for a module, applying pre-flight safety and post-load boundary checks.
     *
     * @param  (callable(string): void)|null  $routeLoader
     */
    public function registerForModule(string $moduleName, string $modulePath, string $moduleSource = 'core', ?callable $routeLoader = null): void
    {
        $routesPath = is_file($modulePath) ? $modulePath : rtrim($modulePath, '/').'/routes/web.php';

        if (! file_exists($routesPath)) {
            return;
        }

        $isCustom = $moduleSource === 'custom';

        $app = app();
        $routesCached = $app instanceof CachesRoutes && $app->routesAreCached();

        $snapshot = ($isCustom && ! $routesCached) ? Route::getRoutes()->getRoutes() : [];

        if ($isCustom && ! $this->assertRouteFileIsSafe($routesPath)) {
            return;
        }

        if ($routeLoader !== null) {
            $routeLoader($routesPath);
        } elseif (! $routesCached) {
            require $routesPath;
        }

        Route::getRoutes()->refreshNameLookups();

        if ($isCustom && ! $routesCached) {
            $guard = $this->guard ?? new ModuleCollisionGuard;
            $guard->enforceRouteBoundaries($snapshot);
        }
    }

    /**
     * Run the route collision guard over a custom module's route file.
     */
    public function assertRouteFileIsSafe(string $routesPath): bool
    {
        $guard = $this->guard ?? new ModuleCollisionGuard;
        $registeredNames = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (($name = $route->getName()) !== null) {
                $registeredNames[] = $name;
            }
        }

        return $guard->assertNoRouteCollision(
            $guard->extractRouteDescriptors($routesPath),
            [
                'uris' => ModuleCollisionGuard::RESERVED_PANEL_URIS,
                'names' => $registeredNames,
            ],
        );
    }
}
