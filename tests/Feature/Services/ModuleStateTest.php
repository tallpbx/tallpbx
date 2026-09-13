<?php

declare(strict_types=1);

use App\Models\Module;
use App\Services\MenuService;
use App\Services\ModuleState;
use App\Services\PermissionService;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    app()->forgetInstance(ModuleState::class);
});

it('treats missing module registry rows as enabled', function () {
    $state = app(ModuleState::class);

    expect($state->isEnabled('not-yet-synced'))->toBeTrue();
});

it('treats modules as enabled before the registry table exists', function () {
    Schema::shouldReceive('hasTable')
        ->once()
        ->with('modules')
        ->andReturn(false);

    $state = app(ModuleState::class);

    expect($state->isEnabled('extensions'))->toBeTrue();
});

it('reads disabled module state from the module registry', function () {
    Module::create([
        'name' => 'extensions',
        'display_name' => 'Extensions',
        'version' => '1.0.0',
        'enabled' => false,
    ]);

    $state = app(ModuleState::class);

    expect($state->isEnabled('extensions'))->toBeFalse();
});

it('treats uninstalled modules as disabled at runtime', function () {
    Module::create([
        'name' => 'extensions',
        'display_name' => 'Extensions',
        'version' => '1.0.0',
        'enabled' => false,
        'status' => Module::StatusUninstalled,
    ]);

    $state = app(ModuleState::class);

    expect($state->isEnabled('extensions'))->toBeFalse();
});

it('resolves module state from module class namespaces', function () {
    Module::create([
        'name' => 'inbound-routes',
        'display_name' => 'Inbound Routes',
        'version' => '1.0.0',
        'enabled' => false,
    ]);

    $state = app(ModuleState::class);

    expect($state->isEnabledForClass('Modules\\InboundRoutes\\Services\\InboundRouteService'))->toBeFalse()
        ->and($state->isEnabledForClass('App\\Services\\DialplanXmlCollector'))->toBeTrue();
});

it('caches module state lookups for the current request', function () {
    Module::create([
        'name' => 'inbound-routes',
        'display_name' => 'Inbound Routes',
        'version' => '1.0.0',
        'enabled' => true,
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $state = app(ModuleState::class);

    expect($state->isEnabled('inbound-routes'))->toBeTrue()
        ->and($state->isEnabled('inbound-routes'))->toBeTrue()
        ->and($state->isEnabledForClass('Modules\\InboundRoutes\\Services\\InboundRouteService'))->toBeTrue();

    $moduleQueries = collect($queries)
        ->filter(fn (string $sql): bool => str_contains($sql, 'from "modules"') || str_contains($sql, 'from `modules`'))
        ->count();

    expect($moduleQueries)->toBe(1);
});

it('does not register menu items or permissions for disabled base modules', function () {
    Module::create([
        'name' => 'disabled-test-module',
        'display_name' => 'Disabled Test Module',
        'version' => '1.0.0',
        'enabled' => false,
    ]);

    $menu = new MenuService;
    $permissions = new PermissionService;
    $provider = new class(app()) extends ModuleServiceProvider
    {
        protected function moduleName(): string
        {
            return 'disabled-test-module';
        }

        protected function moduleNamespace(): string
        {
            return 'Modules\\DisabledTestModule';
        }

        protected function menuItems(): array
        {
            return [
                [
                    'key' => 'disabled-test-module',
                    'label' => 'Disabled Test Module',
                    'route' => 'panel.disabled-test-module.index',
                    'permission' => 'disabled-test-module.view',
                    'icon' => 'heroicon-o-x-circle',
                    'order' => 10,
                ],
            ];
        }

        protected function permissions(): array
        {
            return [
                'disabled-test-module.view' => 'View disabled test module',
            ];
        }
    };

    $provider->boot($menu, $permissions, app(ModuleState::class));

    expect($menu->getFlat())->toBeEmpty()
        ->and($permissions->all())->toBeEmpty();
});
