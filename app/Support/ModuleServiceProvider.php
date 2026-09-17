<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\MenuService;
use App\Services\ModuleState;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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
        $this->registerMigrations();

        if (! $modules->isEnabled($this->moduleName())) {
            return;
        }

        $this->registerRoutes();
        $this->registerLivewire();
        $this->registerMenu($menu);
        $this->registerPermissions($permission);
        $this->registerListeners();
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
     * Whether this module has translation files to load.
     * Checks for the existence of a resources/lang directory.
     */
    protected function hasTranslations(): bool
    {
        return is_dir($this->modulePath().'/resources/lang');
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
     * Register the module's translation namespace, if translation
     * files exist in resources/lang.
     */
    private function registerTranslations(): void
    {
        if ($this->hasTranslations()) {
            $this->loadTranslationsFrom(
                $this->modulePath().'/resources/lang',
                $this->moduleName(),
            );
        }
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
     * Define the route groups to auto-register for this module.
     *
     * Each group is an associative array with:
     *   - enabled: Whether this group's routes are active
     *   - prefix: URL prefix (e.g., 'panel')
     *   - middleware: Middleware stack for the group
     *   - name_prefix: Route name prefix (e.g., 'panel.')
     *   - permission_callback: Closure that returns the permission string
     *     for a given action (e.g., fn($action) => "admin.can:bridges.$action")
     *   - component_suffixes: Livewire component suffixes to look for
     *     (e.g., ['List', 'Edit'] registers index/create/edit routes)
     *
     * Modules can override this to customize routing. Groups with enabled
     * set to false are skipped. The default single group uses the neutral
     * /panel prefix accessible by both Admin and User models.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function routeGroups(): array
    {
        $moduleName = $this->moduleName();

        return [
            [
                'enabled' => true,
                'prefix' => 'panel',
                'middleware' => ['web', 'auth.panel', 'throttle:60,1'],
                'name_prefix' => 'panel.',
                'permission_callback' => fn (string $action): string => "admin.can:{$moduleName}.{$action}",
                'component_suffixes' => ['List', 'Edit'],
            ],
        ];
    }

    /**
     * Register the module's web routes.
     *
     * If a routes/web.php file exists, it is loaded as-is, giving modules
     * full control over their routing. Otherwise, route groups defined by
     * routeGroups() are auto-registered by convention.
     *
     * Modules with non-standard routing (admin, auth, provision, etc.)
     * keep their route files and are loaded normally.
     */
    private function registerRoutes(): void
    {
        $routesPath = $this->modulePath().'/routes/web.php';

        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);

            return;
        }

        $this->autoRegisterRouteGroups();
    }

    /**
     * Auto-register routes from each enabled route group based on which
     * Livewire component files exist in the module's src/Livewire directory.
     *
     * For each group, {PascalModule}{Suffix}.php components are detected
     * and standard CRUD or read-only routes are created:
     * - List suffix registers an index route
     * - Edit suffix registers create and edit routes
     * - Only index route is created if no Edit component exists
     */
    private function autoRegisterRouteGroups(): void
    {
        $modulePath = $this->modulePath();
        $moduleName = $this->moduleName();
        $pascalName = str_replace(' ', '', ucwords(str_replace('-', ' ', $moduleName)));
        // Support both new (src/Livewire/) and legacy (src/Http/Livewire/) paths
        $usingNewPath = is_dir($modulePath.'/src/Livewire');
        $livewireDir = $usingNewPath
            ? $modulePath.'/src/Livewire'
            : $modulePath.'/src/Http/Livewire';
        $livewireSubnamespace = $usingNewPath ? 'Livewire' : 'Http\Livewire';

        if (! is_dir($livewireDir)) {
            return;
        }

        $groups = $this->routeGroups();

        if ($groups === []) {
            return;
        }

        $namespace = "Modules\\{$pascalName}";

        foreach ($groups as $group) {
            if (! ($group['enabled'] ?? false)) {
                continue;
            }

            $prefix = $group['prefix'] ?? '';
            $middleware = $group['middleware'] ?? [];
            $namePrefix = $group['name_prefix'] ?? '';
            $permissionCallback = $group['permission_callback'] ?? fn () => '';
            $suffixes = $group['component_suffixes'] ?? ['List', 'Edit'];

            $hasList = in_array('List', $suffixes, true)
                && file_exists("{$livewireDir}/{$pascalName}List.php");
            $hasEdit = in_array('Edit', $suffixes, true)
                && file_exists("{$livewireDir}/{$pascalName}Edit.php");

            if (! $hasList && ! $hasEdit) {
                continue;
            }

            $singularSlug = Str::singular($moduleName);

            Route::prefix($prefix)->name($namePrefix)->middleware($middleware)->group(function () use ($moduleName, $namespace, $pascalName, $singularSlug, $hasList, $hasEdit, $permissionCallback, $livewireSubnamespace): void {
                if ($hasList) {
                    $listClass = $namespace.'\\'.$livewireSubnamespace.'\\'.$pascalName.'List';

                    Route::get("/{$moduleName}", $listClass)
                        ->middleware(array_filter([$permissionCallback('view')]))
                        ->name("{$moduleName}.index");
                }

                if ($hasEdit) {
                    $editClass = $namespace.'\\'.$livewireSubnamespace.'\\'.$pascalName.'Edit';
                    $routeParameter = $this->editRouteParameterName($editClass, $singularSlug);

                    Route::get("/{$moduleName}/create", $editClass)
                        ->middleware(array_filter([$permissionCallback('create')]))
                        ->name("{$moduleName}.create");

                    Route::get("/{$moduleName}/{{$routeParameter}}/edit", $editClass)
                        ->middleware(array_filter([$permissionCallback('edit')]))
                        ->name("{$moduleName}.edit");
                }
            });
        }
    }

    /**
     * Resolve the route parameter name expected by an edit Livewire component.
     *
     * Livewire full-page components receive route parameters by matching the
     * parameter name to mount() arguments. Most modules use a domain-specific
     * argument such as $extensionId instead of the URL slug's singular name.
     */
    private function editRouteParameterName(string $editClass, string $fallback): string
    {
        if (! class_exists($editClass) || ! method_exists($editClass, 'mount')) {
            return $fallback;
        }

        $method = new \ReflectionMethod($editClass, 'mount');
        $parameter = $method->getParameters()[0] ?? null;

        return $parameter?->getName() ?? $fallback;
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
