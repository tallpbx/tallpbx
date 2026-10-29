<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\Modules\ModuleManifestLoader;
use App\Services\Modules\ModulePermissionRegistrar;
use App\Services\Modules\ModuleProtectionPolicy;
use App\Services\Modules\ModuleRouteRegistrar;
use App\Services\PermissionService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

describe('ModuleCollaborators', function (): void {
    beforeEach(function (): void {
        $this->sandbox = sys_get_temp_dir().'/pbx-collaborators-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->sandbox, 0755, true);
    });

    afterEach(function (): void {
        if (File::isDirectory($this->sandbox)) {
            File::deleteDirectory($this->sandbox);
        }
    });

    describe('ModuleProtectionPolicy', function (): void {
        it('recognizes protected and required modules', function (): void {
            $policy = new ModuleProtectionPolicy;

            expect($policy->isProtected(['name' => 'test', 'protected' => true]))->toBeTrue();
            expect($policy->isProtected(['name' => 'test', 'required' => true]))->toBeTrue();
            expect($policy->isProtected(['name' => 'test', 'protected' => true, 'required' => false]))->toBeTrue();
            expect($policy->isProtected(['name' => 'test', 'protected' => false, 'required' => true]))->toBeTrue();
            expect($policy->isProtected(['name' => 'test', 'protected' => false, 'required' => false]))->toBeFalse();
            expect($policy->isProtected(['name' => 'test']))->toBeFalse();
            expect($policy->isProtected([]))->toBeFalse();
        });

        it('asserts uninstallation safety and throws on protected or required modules', function (): void {
            $policy = new ModuleProtectionPolicy;

            expect(fn () => $policy->assertCanUninstall(['name' => 'core-mod', 'protected' => true]))
                ->toThrow(InvalidArgumentException::class, 'Required and protected modules cannot be uninstalled.');

            expect(fn () => $policy->assertCanUninstall(['name' => 'required-mod', 'required' => true]))
                ->toThrow(InvalidArgumentException::class, 'Required and protected modules cannot be uninstalled.');

            // Unprotected and non-required module does not throw
            $policy->assertCanUninstall(['name' => 'custom-mod', 'protected' => false, 'required' => false]);
            $policy->assertCanUninstall([]);
            expect(true)->toBeTrue();
        });
    });

    describe('ModuleManifestLoader', function (): void {
        it('discovers and sorts installed modules in the application', function (): void {
            $loader = new ModuleManifestLoader;
            $modules = $loader->discoverAll();

            expect($modules)->toBeArray();
            expect($modules)->toHaveKey('admin');
            expect($modules)->toHaveKey('extensions');
            expect($modules['admin'])->toHaveKey('path');
            expect($modules['admin'])->toHaveKey('source');
        });

        it('discovers and topologically sorts module manifests in sandbox', function (): void {
            // Create module-b which depends on module-a
            File::makeDirectory($this->sandbox.'/app-modules/module-b', 0755, true);
            File::put($this->sandbox.'/app-modules/module-b/module.json', json_encode([
                'name' => 'module-b',
                'version' => '1.0.0',
                'namespace' => 'Modules\\ModuleB',
                'display_name' => 'Module B',
                'priority' => 10,
                'requirements' => [
                    'modules' => ['module-a'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Create module-a with higher priority number (would sort second without dependency)
            File::makeDirectory($this->sandbox.'/app-modules/module-a', 0755, true);
            File::put($this->sandbox.'/app-modules/module-a/module.json', json_encode([
                'name' => 'module-a',
                'version' => '1.0.0',
                'namespace' => 'Modules\\ModuleA',
                'display_name' => 'Module A',
                'priority' => 99,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $loader = new ModuleManifestLoader($this->sandbox);
            $manifests = $loader->discoverAll();

            expect(array_keys($manifests))->toBe(['module-a', 'module-b'])
                ->and($manifests['module-a']['name'])->toBe('module-a')
                ->and($manifests['module-b']['name'])->toBe('module-b');
        });

        it('loads manifests from bootstrap cache when available', function (): void {
            $cacheDir = $this->sandbox.'/bootstrap/cache';
            File::makeDirectory($cacheDir, 0755, true);
            $cachedManifests = [
                'cached-mod' => [
                    'name' => 'cached-mod',
                    'version' => '2.0.0',
                    'namespace' => 'Modules\\CachedMod',
                    'display_name' => 'Cached Module',
                    'source' => 'core',
                    'enabled' => true,
                ],
            ];

            File::put($cacheDir.'/modules.php', '<?php return '.var_export($cachedManifests, true).';');

            $loader = new ModuleManifestLoader($this->sandbox);
            $manifests = $loader->discoverAll();

            expect($manifests)->toHaveKey('cached-mod')
                ->and($manifests['cached-mod']['version'])->toBe('2.0.0');
        });
    });

    describe('ModulePermissionRegistrar', function (): void {
        it('registers permissions with PermissionService', function (): void {
            $permissionService = new PermissionService;
            $registrar = new ModulePermissionRegistrar($permissionService);

            $registrar->registerForModule('test-module', [
                'test.view' => 'View test module',
                'test.manage' => 'Manage test module',
            ]);

            expect($permissionService->all())->toContain('test.view', 'test.manage')
                ->and($permissionService->grouped()['test-module'])->toEqualCanonicalizing([
                    ['name' => 'test.view', 'description' => 'View test module'],
                    ['name' => 'test.manage', 'description' => 'Manage test module'],
                ]);
        });

        it('handles empty permissions cleanly', function (): void {
            $permissionService = new PermissionService;
            $registrar = new ModulePermissionRegistrar($permissionService);

            $registrar->registerForModule('empty-module', []);

            expect($permissionService->all())->not->toContain('empty-module');
        });
    });

    describe('ModuleRouteRegistrar', function (): void {
        it('detects missing route files gracefully', function (): void {
            $registrar = new ModuleRouteRegistrar;
            $called = false;

            $registrar->registerForModule('fake-mod', '/tmp/nonexistent-path', 'core', function () use (&$called): void {
                $called = true;
            });

            expect($called)->toBeFalse();
        });

        it('registers routes from a module routes directory', function (): void {
            $moduleDir = $this->sandbox.'/app-modules/test-module';
            File::makeDirectory($moduleDir.'/routes', 0755, true);
            File::put($moduleDir.'/routes/web.php', "<?php\n\nuse Illuminate\Support\Facades\Route;\n\nRoute::get('/test-collab-route', fn () => 'ok')->name('test.collab.route');\n");

            $registrar = new ModuleRouteRegistrar;
            $registrar->registerForModule('test-module', $moduleDir, 'core');

            $route = Route::getRoutes()->getByName('test.collab.route');
            expect($route)->not->toBeNull()
                ->and($route->uri())->toBe('test-collab-route');
        });

        it('identifies unsafe route collisions with reserved panel URIs', function (): void {
            $routesPath = $this->sandbox.'/routes-unsafe.php';
            File::put($routesPath, "<?php\n\nuse Illuminate\Support\Facades\Route;\n\nRoute::get('/panel/dashboard', fn () => 'hijacked');\n");

            $registrar = new ModuleRouteRegistrar;
            expect($registrar->assertRouteFileIsSafe($routesPath))->toBeFalse();
        });

        it('identifies safe route files', function (): void {
            $routesPath = $this->sandbox.'/routes-safe.php';
            File::put($routesPath, "<?php\n\nuse Illuminate\Support\Facades\Route;\n\nRoute::get('/panel/my-safe-custom-tool', fn () => 'ok')->name('panel.custom.tool.safe');\n");

            $registrar = new ModuleRouteRegistrar;
            expect($registrar->assertRouteFileIsSafe($routesPath))->toBeTrue();
        });
    });
});
