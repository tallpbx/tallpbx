<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\MenuService;
use App\Services\ModuleCollisionGuard;
use App\Services\ModuleState;
use App\Services\PermissionService;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Base service provider for all PBX modules.
 *
 * Each module provider extends this class and only needs to define its
 * configuration (module name, namespace, menu items, permissions) instead
 * of repeating the same boot logic. Service container bindings should be
 * declared using Laravel 13's built-in $bindings / $singletons properties,
 * which the framework auto-registers without needing a register() method.
 *
 * Paths to views, migrations, and routes are resolved automatically via
 * reflection against the child provider's file location, assuming the
 * standard module directory layout:
 *   app-modules/{name}/
 *   ├── database/migrations/
 *   ├── resources/views/
 *   ├── routes/
 *   │   └── web.php
 *   └── src/
 *       ├── Livewire/
 *       ├── Models/
 *       └── Providers/ModuleServiceProvider.php
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Return the module name in kebab-case format, used as the view
     * namespace, Livewire namespace, and menu/permission group key.
     *
     * Examples: 'bridges', 'active-calls', 'sip-profiles'.
     */
    abstract protected function moduleName(): string;

    /**
     * Return the PHP root namespace for this module's classes.
     *
     * Examples: 'Modules\\Bridges', 'Modules\\ActiveCalls'.
     */
    abstract protected function moduleNamespace(): string;

    /**
     * Register module views, translations, migrations, routes, Livewire
     * components, menu entries, and permissions.
     *
     * MenuService and PermissionService are injected automatically via
     * Laravel 13's boot-method dependency injection.
     */
    public function boot(MenuService $menu, PermissionService $permission, ModuleState $modules): void
    {
        $this->registerViews();
        $this->registerTranslations();
        $this->registerConfig();

        $isEnabled = $modules->isEnabled($this->moduleName());

        if ($isEnabled || $modules->shouldMigrateAll()) {
            $this->registerMigrations();
        }

        if (! $isEnabled) {
            return;
        }

        $this->registerRoutes();
        $this->registerLivewire();
        $this->registerMenu($menu);
        $this->registerPermissions($permission);
        $this->registerListeners();
        $this->registerCommands();
    }

    // ─── Path resolution ────────────────────────────────────────────────

    /**
     * Resolve the module root directory from the child provider's file
     * location. Assumes the provider lives at:
     *   {moduleRoot}/src/Providers/ModuleServiceProvider.php
     *
     * @return string Absolute path to the module root, e.g. /var/www/tallpbx/app-modules/bridges
     */
    protected function modulePath(): string
    {
        $reflection = new \ReflectionClass(static::class);

        return dirname($reflection->getFileName(), 3);
    }

    /**
     * Whether this module has database migrations to load.
     * Checks for the existence of a database/migrations directory.
     */
    protected function hasMigrations(): bool
    {
        return is_dir($this->modulePath().'/database/migrations');
    }

    /**
     * Resolve the module's translation directory path if it exists.
     * Checks lang/ first (modern convention), then resources/lang/ (legacy fallback).
     */
    protected function translationPath(): ?string
    {
        $base = $this->modulePath();
        if (is_dir("{$base}/lang")) {
            return "{$base}/lang";
        }
        if (is_dir("{$base}/resources/lang")) {
            return "{$base}/resources/lang";
        }

        return null;
    }

    /**
     * Whether this module has translation files to load.
     */
    protected function hasTranslations(): bool
    {
        return $this->translationPath() !== null;
    }

    /**
     * Whether this module ships its own configuration file.
     * Checks for the existence of a config/{moduleName}.php file.
     */
    protected function hasConfig(): bool
    {
        return is_file($this->modulePath().'/config/'.$this->moduleName().'.php');
    }

    // ─── Menu and permission configuration ───────────────────────────────

    /**
     * Return the menu items to register for this module.
     *
     * Each item is an associative array with keys: key, label, route (optional),
     * permission (optional), icon, parent (optional), order.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function menuItems(): array
    {
        return [];
    }

    /**
     * Return the permissions to register for this module.
     *
     * Format: [permission_key => human_readable_description].
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [];
    }

    /**
     * Return the event listeners to register for this module.
     *
     * Format: [EventClass => [ListenerClass, ...]] or [EventClass => ListenerClass].
     *
     * @return array<class-string, class-string|array<int, class-string>>
     */
    protected function listeners(): array
    {
        return [];
    }

    /**
     * Return the Artisan console commands to register for this module.
     *
     * @return array<int, class-string>
     */
    protected function consoleCommands(): array
    {
        return [];
    }

    // ─── Private registration helpers ────────────────────────────────────

    /**
     * Register module event listeners with the Laravel Event dispatcher.
     */
    private function registerListeners(): void
    {
        foreach ($this->listeners() as $event => $listeners) {
            foreach ((array) $listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    /**
     * Register module console commands when running in console.
     */
    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $commands = $this->consoleCommands();

            if ($commands !== []) {
                $this->commands($commands);
            }
        }
    }

    /**
     * Register the module's Blade view namespace.
     * Views are loaded from resources/views using the module name as
     * the namespace (e.g., 'bridges::bridges-list').
     */
    private function registerViews(): void
    {
        $this->loadViewsFrom(
            $this->modulePath().'/resources/views',
            $this->moduleName(),
        );
    }

    /**
     * Cache of root translation keys by locale and group.
     *
     * @var array<string, array<string, int>>
     */
    private static array $rootGroupKeysCache = [];

    /**
     * Cascading translation keys contributed by every registered module,
     * indexed by locale, group, then module name. Used by the inter-module
     * collision check so a custom module cannot shadow keys another module
     * already cascades into the global dictionary (plan §1.7.4).
     *
     * @var array<string, array<string, array<string, array<string, int>>>>
     */
    private static array $moduleTranslationKeys = [];

    /**
     * Clear the static root translation keys cache.
     */
    public static function clearRootTranslationKeysCache(): void
    {
        self::$rootGroupKeysCache = [];
    }

    /**
     * Clear the static module translation keys registry (primarily for tests).
     */
    public static function clearModuleTranslationKeysCache(): void
    {
        self::$moduleTranslationKeys = [];
    }

    /**
     * Resolve this module's declared source (core, custom, or vendor).
     *
     * Defaults to 'core' whenever the manifest is not resolvable, so only
     * explicitly custom modules receive the stricter custom-extension guards.
     */
    protected function moduleSource(): string
    {
        try {
            if (! $this->app->bound('modules.manifest')) {
                return 'core';
            }

            $manifests = $this->app->make('modules.manifest');

            return is_array($manifests) ? ($manifests[$this->moduleName()]['source'] ?? 'core') : 'core';
        } catch (\Throwable) {
            return 'core';
        }
    }

    /**
     * Register module translation paths, namespaces, and cascading loader.
     *
     * Registers both namespaced translations ({moduleName}::{group}.{key})
     * and adds the module path to the translation loader for native path cascading
     * into the global dictionary ({group}.{key}).
     *
     * Before adding the cascading path, validates that no keys in the module's
     * language files collide with keys in the root global dictionary and —
     * for custom modules — with keys another module already cascades. On a
     * custom-module collision the cascading path is revoked and the module
     * falls back to its namespaced translations only (plan §1.7.4).
     */
    private function registerTranslations(): void
    {
        $path = $this->translationPath();
        if ($path === null) {
            return;
        }

        $this->loadTranslationsFrom($path, $this->moduleName());

        $collisions = $this->detectRootTranslationCollisions($path);
        if (! empty($collisions)) {
            $formatted = implode(', ', $collisions);
            $message = "Module [{$this->moduleName()}] language file collides with root dictionary for key(s): [{$formatted}]. Refusing to load module cascading path.";

            if ($this->app->environment('local', 'testing')) {
                throw new \LogicException($message);
            }

            Log::critical($message);

            return;
        }

        // Inter-module layer (§1.7.4): a custom module must not shadow keys
        // another module already cascades into the global dictionary.
        if ($this->moduleSource() === 'custom') {
            $moduleCollisions = $this->detectModuleTranslationCollisions($path);

            if ($moduleCollisions !== []) {
                $formatted = implode(', ', $moduleCollisions);

                Log::critical("Custom module [{$this->moduleName()}] language keys collide with another module's dictionary for key(s): [{$formatted}]. Cascading path revoked; use namespaced {$this->moduleName()}:: translations.");

                return;
            }
        }

        $loader = $this->app->make('translation.loader');
        if (method_exists($loader, 'addPath')) {
            $loader->addPath($path);
        }

        $this->rememberModuleTranslationKeys($path);
    }

    /**
     * Detect any key collisions between a custom module's translations and
     * the dictionaries of previously registered modules.
     *
     * @return array<int, string> Colliding keys in {group}.{key} format
     */
    protected function detectModuleTranslationCollisions(string $path): array
    {
        $collisions = [];

        foreach ($this->collectTranslationKeys($path) as $locale => $groups) {
            foreach ($groups as $group => $keys) {
                foreach (self::$moduleTranslationKeys[$locale][$group] ?? [] as $otherModule => $otherKeys) {
                    if ($otherModule === $this->moduleName()) {
                        continue;
                    }

                    foreach (array_keys(array_intersect_key($keys, $otherKeys)) as $key) {
                        $collisionEntry = "{$group}.{$key}";

                        if (! in_array($collisionEntry, $collisions, true)) {
                            $collisions[] = $collisionEntry;
                        }
                    }
                }
            }
        }

        return $collisions;
    }

    /**
     * Record a module's cascading translation keys for future collision checks.
     */
    private function rememberModuleTranslationKeys(string $path): void
    {
        foreach ($this->collectTranslationKeys($path) as $locale => $groups) {
            foreach ($groups as $group => $keys) {
                self::$moduleTranslationKeys[$locale][$group][$this->moduleName()] = $keys;
            }
        }
    }

    /**
     * Collect a module's translation keys indexed by locale and group.
     *
     * @return array<string, array<string, array<string, int>>> Locale => group => flipped key list.
     */
    private function collectTranslationKeys(string $path): array
    {
        $collected = [];

        foreach (glob("{$path}/*", GLOB_ONLYDIR) ?: [] as $localeDir) {
            $locale = basename($localeDir);

            foreach (glob("{$localeDir}/*.php") ?: [] as $file) {
                $group = basename($file, '.php');
                $content = require $file;

                if (! is_array($content)) {
                    continue;
                }

                $collected[$locale][$group] = array_flip(array_keys(Arr::dot($content)));
            }
        }

        return $collected;
    }

    /**
     * Detect any key collisions between a module translation directory and the root dictionary.
     *
     * @return array<int, string> Colliding keys in {group}.{key} format
     */
    protected function detectRootTranslationCollisions(string $path): array
    {
        $collisions = [];
        $localeDirs = glob("{$path}/*", GLOB_ONLYDIR) ?: [];

        foreach ($localeDirs as $localeDir) {
            $locale = basename($localeDir);
            $files = glob("{$localeDir}/*.php") ?: [];

            foreach ($files as $file) {
                $group = basename($file, '.php');
                $cacheKey = "{$locale}.{$group}";
                $rootFile = base_path("lang/{$locale}/{$group}.php");

                if (! file_exists($rootFile)) {
                    continue;
                }

                if (! isset(self::$rootGroupKeysCache[$cacheKey])) {
                    $rootContent = require $rootFile;
                    $rootKeys = is_array($rootContent) ? array_keys(Arr::dot($rootContent)) : [];
                    self::$rootGroupKeysCache[$cacheKey] = array_flip($rootKeys);
                }

                $moduleContent = require $file;
                if (! is_array($moduleContent)) {
                    continue;
                }

                $moduleKeys = array_keys(Arr::dot($moduleContent));
                foreach ($moduleKeys as $mKey) {
                    if (isset(self::$rootGroupKeysCache[$cacheKey][$mKey])) {
                        $collisionEntry = "{$group}.{$mKey}";
                        if (! in_array($collisionEntry, $collisions, true)) {
                            $collisions[] = $collisionEntry;
                        }
                    }
                }
            }
        }

        return $collisions;
    }

    /**
     * Resolve all migration paths across all installed modules.
     *
     * @return array<int, string>
     */
    public static function allMigrationPaths(): array
    {
        $paths = [];
        $candidates = array_merge(
            glob(base_path('app-modules/*/database/migrations')) ?: [],
            glob(base_path('custom-modules/*/database/migrations')) ?: [],
            glob(base_path('vendor/*/*/database/migrations')) ?: [],
        );

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                $paths[] = $dir;
            }
        }

        return $paths;
    }

    /**
     * Register the module's database migrations, if a migrations
     * directory exists.
     */
    private function registerMigrations(): void
    {
        if ($this->hasMigrations()) {
            $this->loadMigrationsFrom(
                $this->modulePath().'/database/migrations',
            );
        }
    }

    /**
     * Register the module's configuration file when one exists.
     *
     * Module settings are merged over the centralized app-level defaults
     * under the module's own key: module values win on conflicts while
     * every central key the module does not touch survives.
     */
    private function registerConfig(): void
    {
        if (! $this->hasConfig()) {
            return;
        }

        $key = $this->moduleName();
        $config = $this->app->make('config');

        $config->set($key, array_merge(
            $config->get($key, []),
            require $this->modulePath().'/config/'.$key.'.php',
        ));
    }

    /**
     * Register the module's web routes from routes/web.php.
     *
     * Every module defines its routes explicitly in routes/web.php.
     * Custom modules are subjected to pre-flight safety and post-load
     * collision enforcement against reserved URLs and system routes.
     */
    private function registerRoutes(): void
    {
        $routesPath = $this->modulePath().'/routes/web.php';

        if (! file_exists($routesPath)) {
            return;
        }

        $isCustom = $this->moduleSource() === 'custom';

        // With cached routes the router holds a compiled collection that
        // never loads this file anyway (loadRoutesFrom is a no-op), so
        // there is no snapshot to take and no audit to run.
        $routesCached = $this->app instanceof CachesRoutes
            && $this->app->routesAreCached();

        // Snapshot the router before a custom module loads its route file
        // so the post-load audit can identify and undo anything it hijacked.
        $snapshot = ($isCustom && ! $routesCached) ? Route::getRoutes()->getRoutes() : [];

        // Static pre-flight (§1.7.2) stays as the cheap first line of
        // defense; the post-load router audit catches every registration
        // style it cannot see statically.
        if ($isCustom && ! $this->assertRouteFileIsSafe($routesPath)) {
            return;
        }

        $this->loadRoutesFrom($routesPath);

        if ($isCustom && ! $routesCached) {
            (new ModuleCollisionGuard)->enforceRouteBoundaries($snapshot);
        }
    }

    /**
     * Run the route collision guard over a custom module's route file.
     *
     * Reserved URLs come from the guard's fixed panel list; reserved route
     * names are every name already registered by the application.
     */
    private function assertRouteFileIsSafe(string $routesPath): bool
    {
        $guard = new ModuleCollisionGuard;
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

    /**
     * Register the module's Livewire component namespace so that
     * Livewire can discover SFC and MFC components under the
     * module's namespace (e.g., bridges::bridges-list).
     */
    private function registerLivewire(): void
    {
        $name = $this->moduleName();
        $namespace = $this->moduleNamespace();
        $modulePath = $this->modulePath();

        // Support both new (src/Livewire/) and legacy (src/Http/Livewire/) paths
        $usingNewPath = is_dir($modulePath.'/src/Livewire');
        $livewireSubnamespace = $usingNewPath ? 'Livewire' : 'Http\\Livewire';
        $classPath = $usingNewPath ? $modulePath.'/src/Livewire' : $modulePath.'/src/Http/Livewire';
        $viewPath = $usingNewPath ? "{$modulePath}/resources/views" : "{$modulePath}/resources/views/livewire";

        Livewire::addNamespace(
            $name,
            classNamespace: $namespace.'\\'.$livewireSubnamespace,
            viewPath: $viewPath,
            classPath: $classPath,
            classViewPath: $viewPath,
        );
    }

    /**
     * Register menu items with the MenuService, if any are defined.
     */
    private function registerMenu(MenuService $menu): void
    {
        $items = $this->menuItems();

        if ($items !== []) {
            $menu->register($this->moduleName(), $items);
        }
    }

    /**
     * Register permissions with the PermissionService, if any are defined.
     */
    private function registerPermissions(PermissionService $permission): void
    {
        $perms = $this->permissions();

        if ($perms !== []) {
            $permission->register($this->moduleName(), $perms);
        }
    }
}
