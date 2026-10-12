<?php

declare(strict_types=1);

use App\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

// ─── Registration ───────────────────────────────────────────────

it('is registered in the application service providers list', function () {
    $providers = config('app.providers', []);
    $bootstrapProviders = require base_path('bootstrap/providers.php');

    $found = false;
    foreach ($bootstrapProviders as $provider) {
        if (is_string($provider) && str_contains($provider, 'ModuleServiceProvider')) {
            $found = true;
            break;
        }
    }

    expect($found)->toBeTrue('ModuleServiceProvider must be registered in bootstrap/providers.php');
});

it('can be resolved from the container', function () {
    $provider = App::getProvider(ModuleServiceProvider::class);

    expect($provider)->toBeInstanceOf(ModuleServiceProvider::class);
});

it('discovers modules and tags them with their source', function () {
    $manifest = App::make('modules.manifest');

    expect($manifest['admin']['source'])->toBe('core')
        ->and($manifest['extensions']['source'])->toBe('core');
});

it('discovers representative module providers dynamically', function () {
    $adminProvider = App::getProvider(Modules\Admin\Providers\ModuleServiceProvider::class);
    $extensionsProvider = App::getProvider(Modules\Extensions\Providers\ModuleServiceProvider::class);

    expect($adminProvider)->toBeInstanceOf(Modules\Admin\Providers\ModuleServiceProvider::class)
        ->and($extensionsProvider)->toBeInstanceOf(Modules\Extensions\Providers\ModuleServiceProvider::class);
});

// ─── Module Manifest ────────────────────────────────────────────

it('discovers modules from the filesystem', function () {
    $manifest = App::make('modules.manifest');

    expect($manifest)->toBeArray();
    expect($manifest)->not->toBeEmpty();
});

it('discovers the admin module with correct metadata', function () {
    $manifest = App::make('modules.manifest');

    expect($manifest)->toHaveKey('admin');
    expect($manifest['admin']['display_name'])->toBe('Admin');
    expect($manifest['admin']['version'])->toBe('1.0.0');
    expect($manifest['admin']['namespace'])->toBe('Modules\\Admin');
});

it('discovers all 15 local modules', function () {
    $manifest = App::make('modules.manifest');

    $localModules = [
        'admin', 'auth', 'tenant',
        'sip-profiles', 'sip-accounts', 'extensions', 'devices',
        'gateways', 'acl', 'feature-codes',
        'dialplans', 'destinations', 'inbound-routes', 'outbound-routes',
        'ivr-menus',
    ];

    foreach ($localModules as $name) {
        expect($manifest)->toHaveKey($name);
    }
});

it('sorts modules by priority', function () {
    $manifest = App::make('modules.manifest');

    $priorities = array_map(fn (array $m): int => $m['priority'] ?? 0, $manifest);
    $sorted = $priorities;
    asort($sorted);

    expect(array_values($priorities))->toBe(array_values($sorted));
});

// ─── Module Providers ───────────────────────────────────────────

it('registers module service providers', function () {
    // Verify that the admin module's provider was registered
    $adminProvider = App::getProvider(Modules\Admin\Providers\ModuleServiceProvider::class);

    expect($adminProvider)->toBeInstanceOf(Modules\Admin\Providers\ModuleServiceProvider::class);
});

it('loads module views through the namespace', function () {
    // Verify that admin module views are accessible
    $viewExists = view()->exists('admin::dashboard');

    expect($viewExists)->toBeTrue();
});

it('loads module routes', function () {
    // Verify that a known admin module route exists
    $route = Route::getRoutes()->getByName('panel.groups.index');

    expect($route)->not->toBeNull();
});
