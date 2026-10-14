<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Pre-flight collision guard for third-party (custom) extensions.
 *
 * Custom modules are untrusted, community-supplied code: before the
 * appliance lets one register routes, tables, or names, this guard verifies
 * the extension does not shadow anything the core appliance already owns
 * (plan §1.7). Every check logs a descriptive error and reports failure by
 * returning false, so callers can reject the extension without crashing boot.
 */
final class ModuleCollisionGuard
{
    /**
     * Core panel URLs a custom module may never bind, from plan §1.7.2.
     *
     * @var array<int, string>
     */
    public const RESERVED_PANEL_URIS = [
        '/panel/login',
        '/panel/setup',
        '/panel/dashboard',
        '/panel/settings',
        '/panel/modules',
        '/panel/git-update',
    ];

    /**
     * Core registry tables a custom migration may never create or alter,
     * from plan §1.7.3.
     *
     * @var array<int, string>
     */
    public const PROTECTED_CORE_TABLES = [
        'users',
        'admins',
        'tenants',
        'groups',
        'permissions',
        'modules',
        'menus',
    ];

    /**
     * Check whether a module name is free of collisions with already-known modules.
     *
     * Logs a descriptive error and returns false when the module's name
     * would shadow an installed (core or custom) module.
     *
     * @param  array<int, string>  $installedModules  Names of already-discovered modules.
     * @param  string  $source  Origin of the colliding module ('custom' or 'vendor').
     */
    public function assertNoNameCollision(string $name, array $installedModules, string $source = 'custom'): bool
    {
        if (! in_array($name, $installedModules, true)) {
            return true;
        }

        $label = ucfirst($source);
        $remedy = $source === 'vendor'
            ? 'Uninstall or rename the vendor package to resolve the collision.'
            : "Rename or remove custom-modules/{$name} to resolve the collision.";

        Log::error(
            "{$label} module [{$name}] declares a name that collides with the installed module [{$name}]. "
            ."The {$source} module was rejected and will not be registered. {$remedy}"
        );

        return false;
    }

    /**
     * Check whether custom route descriptors avoid reserved URLs and existing route names.
     *
     * Logs a descriptive error and returns false on the first collision.
     *
     * @param  array<int, array{uri?: string|null, name?: string|null}>  $routes  Route descriptors extracted from a custom route file.
     * @param  array{uris?: array<int, string>, names?: array<int, string>}  $reservedRoutes  Reserved panel URIs and already-registered route names.
     */
    public function assertNoRouteCollision(array $routes, array $reservedRoutes): bool
    {
        $reservedUris = array_map(
            fn (string $uri): string => '/'.trim($uri, '/'),
            $reservedRoutes['uris'] ?? [],
        );
        $reservedNames = $reservedRoutes['names'] ?? [];

        foreach ($routes as $route) {
            $uri = $route['uri'] ?? null;

            if (is_string($uri) && in_array('/'.trim($uri, '/'), $reservedUris, true)) {
                Log::error(
                    "Custom module route [{$uri}] binds a reserved core panel URL and was rejected. "
                    .'The route file will not be loaded. Choose a module-prefixed URL instead.'
                );

                return false;
            }

            $name = $route['name'] ?? null;

            if (is_string($name) && in_array($name, $reservedNames, true)) {
                Log::error(
                    "Custom module route name [{$name}] hijacks an already-registered route name and was rejected. "
                    .'The route file will not be loaded. Prefix route names with the module name instead.'
                );

                return false;
            }
        }

        return true;
    }

    /**
     * Check whether a table name is free of collisions with protected core tables.
     *
     * Logs a descriptive error and returns false when the custom migration
     * targets a table the core appliance owns.
     */
    public function assertNoTableCollision(string $table, array $reservedTables): bool
    {
        if (! in_array($table, $reservedTables, true)) {
            return true;
        }

        Log::error(
            "Custom module migration targets protected core table [{$table}] and was rejected. "
            .'Custom modules must use their own prefixed tables.'
        );

        return false;
    }

    /**
     * Extract route URI/name descriptors from a custom route file without executing it.
     *
     * Static pre-flight extraction: regular expressions lift the literal
     * URIs from Route::get/post/... calls and the names from ->name() calls.
     * Bare URIs (e.g. 'login') inside a file that declares a panel prefix
     * group are also resolved to their effective /panel/{uri} form so the
     * reserved-URL list cannot be dodged by route grouping.
     *
     * This is only the cheap first line of defense — anything it cannot see
     * (Route::match, mixed-case verbs, redirect/view registrations, variable
     * names and URIs) is caught after loading by enforceRouteBoundaries().
     *
     * @return array<int, array{uri?: string, name?: string}>
     */
    public function extractRouteDescriptors(string $routeFilePath): array
    {
        $source = @file_get_contents($routeFilePath);

        if ($source === false) {
            return [];
        }

        $descriptors = [];

        $panelPrefixed = preg_match("/Route::prefix\(\s*['\"]panel['\"]/", $source) === 1;

        if (preg_match_all("/Route::(?:get|post|put|patch|delete|options|any)\(\s*['\"]([^'\"]+)['\"]/", $source, $uris)) {
            foreach ($uris[1] as $uri) {
                $normalized = '/'.ltrim((string) $uri, '/');
                $descriptors[] = ['uri' => $normalized];

                // A bare URI under a panel-prefix group resolves to /panel/{uri}.
                if ($panelPrefixed && preg_match('#^/?(login|setup|dashboard|settings|modules|git-update)/?$#', (string) $uri)) {
                    $descriptors[] = ['uri' => '/panel/'.trim((string) $uri, '/')];
                }
            }
        }

        if (preg_match_all("/->name\(\s*['\"]([^'\"]+)['\"]\s*\)/", $source, $names)) {
            foreach ($names[1] as $name) {
                $descriptors[] = ['name' => (string) $name];
            }
        }

        return $descriptors;
    }

    /**
     * Audit routes registered since a snapshot and remove custom-module offenders.
     *
     * Compares the router's current state against a snapshot taken before a
     * custom module's route file was loaded: any route ADDED on a reserved
     * core panel URL, or any pre-existing route name now resolving to a
     * different route object, is removed from the collection and the core
     * route it replaced is restored. Because this inspects the actual router
     * state, it catches every registration style the static source scan
     * cannot (Route::match, mixed-case verbs, redirect/view registrations,
     * variable names and URIs).
     *
     * @param  array<int, Route>  $snapshotRoutes  Route objects captured before the custom route file loaded.
     * @return int Number of offending routes removed.
     */
    public function enforceRouteBoundaries(array $snapshotRoutes): int
    {
        // On a route:cache boot the router holds a CompiledRouteCollection:
        // its objects are re-materialized per access (object identity checks
        // would misfire) and its internals differ, so there is nothing to
        // audit. Cached-route boots also never load custom route files in
        // the first place (loadRoutesFrom is a no-op).
        if (! RouteFacade::getRoutes() instanceof RouteCollection) {
            return 0;
        }

        $removed = $this->removeOffendingRoutes($snapshotRoutes);

        if ($removed > 0) {
            $this->restoreReplacedRoutes($snapshotRoutes);
            $this->rebuildMethodMaps();
            RouteFacade::getRoutes()->refreshNameLookups();
            RouteFacade::getRoutes()->refreshActionLookups();
        }

        return $removed;
    }

    /**
     * Remove every route added since the snapshot that binds a reserved URL
     * or hijacked a pre-existing route name.
     *
     * @param  array<int, Route>  $snapshotRoutes
     */
    private function removeOffendingRoutes(array $snapshotRoutes): int
    {
        $snapshotIds = [];
        $snapshotNameOwners = [];

        foreach ($snapshotRoutes as $route) {
            $snapshotIds[spl_object_id($route)] = true;

            if (($name = $route->getName()) !== null) {
                $snapshotNameOwners[$name] = spl_object_id($route);
            }
        }

        $removed = 0;

        foreach (RouteFacade::getRoutes()->getRoutes() as $current) {
            // Only routes the custom module ADDED are auditable — core
            // routes legitimately sitting on reserved URLs are their own.
            if (isset($snapshotIds[spl_object_id($current)])) {
                continue;
            }

            $uri = '/'.trim((string) $current->uri(), '/');
            $name = $current->getName();

            $hijacksUri = in_array($uri, self::RESERVED_PANEL_URIS, true);
            $hijacksName = $name !== null
                && isset($snapshotNameOwners[$name])
                && $snapshotNameOwners[$name] !== spl_object_id($current);

            if (! $hijacksUri && ! $hijacksName) {
                continue;
            }

            $this->forgetRoute($current);
            $removed++;

            $nameSuffix = $name !== null ? " (name [{$name}])" : '';

            Log::error(
                "Custom module route [{$uri}]{$nameSuffix} violated core route boundaries and was removed; the core route it displaced was restored. "
                .'Custom modules must use module-prefixed URLs and route names.'
            );
        }

        return $removed;
    }

    /**
     * Re-add every snapshot route an offender replaced in the collection.
     *
     * Laravel keys the all-routes maps by method-set+domain+URI, so a
     * hijacked registration silently replaced the core route object when
     * their method sets match — re-adding the snapshot original restores
     * its collection entries.
     *
     * @param  array<int, Route>  $snapshotRoutes
     */
    private function restoreReplacedRoutes(array $snapshotRoutes): void
    {
        $currentIds = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            $currentIds[spl_object_id($route)] = true;
        }

        $collection = RouteFacade::getRoutes();

        foreach ($snapshotRoutes as $route) {
            if (! isset($currentIds[spl_object_id($route)])) {
                $collection->add($route);
            }
        }
    }

    /**
     * Remove one route from the router's all-routes collection maps.
     *
     * RouteCollection has no public removal API, so the maps are adjusted
     * through reflection using the same key format the collection itself
     * computes in addToCollections(). The per-method match maps are not
     * touched here — rebuildMethodMaps() reconstructs them afterwards, which
     * also repairs slots an offender with a wider method set (for example a
     * Route::match hijacking GET and POST) displaced from other core routes.
     */
    private function forgetRoute(Route $route): void
    {
        $collection = RouteFacade::getRoutes();
        $allRoutesKey = implode('|', $route->methods()).$route->getDomain().$route->uri();

        $this->mutateCollectionMap($collection, 'allRoutes', function (array &$map) use ($allRoutesKey): void {
            unset($map[$allRoutesKey]);
        });

        $this->mutateCollectionMap($collection, 'allDomainRoutes', function (array &$map) use ($allRoutesKey): void {
            unset($map[$allRoutesKey]);
        });
    }

    /**
     * Rebuild the per-method match maps from the surviving routes.
     *
     * Deleting an offender can leave stale or missing entries in the method
     * maps (offenders with wider method sets replace other routes' slots),
     * so the maps are recomputed from allRoutes + allDomainRoutes exactly
     * the way the collection itself resolves them.
     */
    private function rebuildMethodMaps(): void
    {
        $collection = RouteFacade::getRoutes();
        $methodMap = [];
        $domainMap = [];

        foreach ($collection->getRoutes() as $route) {
            $domainAndUri = $route->getDomain().$route->uri();

            foreach ($route->methods() as $method) {
                if (($route->getDomain() ?? '') !== '') {
                    $domainMap[$method][$domainAndUri] = $route;
                } else {
                    $methodMap[$method][$domainAndUri] = $route;
                }
            }
        }

        (new \ReflectionProperty($collection, 'routes'))->setValue($collection, $methodMap);
        (new \ReflectionProperty($collection, 'domainRoutes'))->setValue($collection, $domainMap);
    }

    /**
     * Apply a by-reference mutation to a protected RouteCollection map property.
     *
     * @param  \Closure(array &$map): void  $mutation
     */
    private function mutateCollectionMap(object $collection, string $property, \Closure $mutation): void
    {
        $reflection = new \ReflectionProperty($collection, $property);
        $map = $reflection->getValue($collection);

        $mutation($map);

        $reflection->setValue($collection, $map);
    }
}
