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

// ─── Manifest Provider Completeness (F2/F11) ────────────────────

it('declares a service provider in every built-in module manifest', function () {
    $manifestPaths = glob(base_path('app-modules/*/module.json'));

    expect($manifestPaths)->toHaveCount(60);

    $missing = [];

    foreach ($manifestPaths as $path) {
        $manifest = json_decode((string) file_get_contents($path), true);
        $name = $manifest['name'] ?? basename(dirname($path));
        $providers = $manifest['providers'] ?? [];

        if ($providers === []) {
            $missing[] = $name;

            continue;
        }

        foreach ($providers as $providerClass) {
            expect(class_exists($providerClass))
                ->toBeTrue("[{$name}] declares provider [{$providerClass}] that does not exist.");
        }
    }

    expect($missing)
        ->toBeEmpty('Every built-in module manifest must declare its service provider. Missing: '.implode(', ', $missing));
});

it('builds the admin panel from module providers that register through the base contract, with the panel-host exception documented', function () {
    // F2 scope is provider DECLARATION. Lineage is informational: every module
    // provider extends App\Support\ModuleServiceProvider except the admin
    // panel-host module, which predates the base contract and extends
    // Illuminate\Support\ServiceProvider directly.
    $nonConforming = [];

    foreach (glob(base_path('app-modules/*/module.json')) as $path) {
        $manifest = json_decode((string) file_get_contents($path), true);

        foreach ($manifest['providers'] ?? [] as $providerClass) {
            // Leading backslash is required: the file imports the App facade,
            // so a relative "App\Support\..." would resolve under the facade namespace.
            if (! is_subclass_of($providerClass, \App\Support\ModuleServiceProvider::class) && $providerClass !== Modules\Admin\Providers\ModuleServiceProvider::class) {
                $nonConforming[] = $manifest['name'];
            }
        }
    }

    expect($nonConforming)->toBeEmpty('Only the admin panel-host module may bypass the module provider base contract. Offenders: '.implode(', ', $nonConforming));
});

it('registers panel routes through manifest providers for backups and email-connector', function () {
    expect(Route::has('panel.backups.index'))->toBeTrue();
    expect(Route::has('panel.email-connector.edit'))
        ->toBeTrue('The email-connector manifest must declare its provider so its routes register.');
});

it('keeps the generated module cache providers in sync with module manifests', function () {
    $cachePath = base_path('bootstrap/cache/modules.php');

    if (! is_file($cachePath)) {
        $this->markTestSkipped('No generated module cache present (cold discovery in effect).');
    }

    $cached = require $cachePath;

    foreach (glob(base_path('app-modules/*/module.json')) as $path) {
        $manifest = json_decode((string) file_get_contents($path), true);
        $name = $manifest['name'];

        expect($cached)->toHaveKey($name);
        expect($cached[$name]['providers'] ?? [])
            ->toBe($manifest['providers'] ?? [], "Generated module cache for [{$name}] is stale — regenerate it with php artisan module:cache.");
    }
});

it('falls back to the conventional provider class when a manifest omits providers', function () {
    expect(ModuleServiceProvider::providersFor(['name' => 'email-connector', 'namespace' => 'Modules\\EmailConnector']))
        ->toBe(['Modules\\EmailConnector\\Providers\\ModuleServiceProvider']);

    expect(ModuleServiceProvider::providersFor([
        'name' => 'admin',
        'namespace' => 'Modules\\Admin',
        'providers' => ['Modules\\Admin\\Providers\\ModuleServiceProvider'],
    ]))->toBe(['Modules\\Admin\\Providers\\ModuleServiceProvider']);

    expect(ModuleServiceProvider::providersFor(['name' => 'ghost', 'namespace' => 'Modules\\Ghost']))
        ->toBe([]);

    // A malformed manifest must never leak a non-array providers value into
    // the discovery loop, where foreach would fatal at boot.
    expect(ModuleServiceProvider::providersFor(['name' => 'broken', 'namespace' => 'Modules\\Broken', 'providers' => 'not-an-array']))
        ->toBe([]);
});

it('resolves version constraints with composer semantics', function () {
    $provider = new ModuleServiceProvider(app());
    $method = new ReflectionMethod($provider, 'satisfiesVersion');

    // A bare-major tilde spans the whole major line: ~1 means >= 1.0 < 2.0,
    // not the 1.0.x patch line the naive next-minor ceiling computes.
    expect($method->invoke($provider, '1.0.0', '~1'))->toBeTrue()
        ->and($method->invoke($provider, '1.9.3', '~1'))->toBeTrue()
        ->and($method->invoke($provider, '2.0.0', '~1'))->toBeFalse()
        // A pinned-minor tilde keeps accepting the current minor line only.
        ->and($method->invoke($provider, '1.2.0', '~1.2'))->toBeTrue()
        ->and($method->invoke($provider, '1.2.9', '~1.2'))->toBeTrue()
        ->and($method->invoke($provider, '1.3.0', '~1.2'))->toBeFalse()
        // Caret keeps accepting everything below the next major.
        ->and($method->invoke($provider, '1.9.9', '^1.2'))->toBeTrue()
        ->and($method->invoke($provider, '2.0.0', '^1.2'))->toBeFalse()
        // Exact and wildcard forms stay intact.
        ->and($method->invoke($provider, '1.2.3', '1.2.3'))->toBeTrue()
        ->and($method->invoke($provider, '1.2.4', '1.2.3'))->toBeFalse()
        ->and($method->invoke($provider, '9.9.9', '*'))->toBeTrue();
});
