<?php

declare(strict_types=1);

use App\Support\ModuleServiceProvider;

it('requires every module to declare an explicit routes/web.php file', function () {
    $missingRouteFiles = [];

    foreach (glob(base_path('app-modules/*'), GLOB_ONLYDIR) as $moduleDir) {
        $moduleName = basename($moduleDir);
        $routeFile = "{$moduleDir}/routes/web.php";

        if (! file_exists($routeFile)) {
            $missingRouteFiles[] = $moduleName;
        }
    }

    expect($missingRouteFiles)->toBeEmpty(
        'All modules must declare an explicit routes/web.php file. Missing in: '.implode(', ', $missingRouteFiles)
    );
});

it('ensures autoRegisterRouteGroups and routeGroups reflection methods are retired', function () {
    $reflector = new ReflectionClass(ModuleServiceProvider::class);

    expect($reflector->hasMethod('autoRegisterRouteGroups'))->toBeFalse(
        'ModuleServiceProvider::autoRegisterRouteGroups() must be retired in favor of explicit routes/web.php files.'
    );

    expect($reflector->hasMethod('routeGroups'))->toBeFalse(
        'ModuleServiceProvider::routeGroups() must be retired in favor of explicit routes/web.php files.'
    );

    expect($reflector->hasMethod('editRouteParameterName'))->toBeFalse(
        'ModuleServiceProvider::editRouteParameterName() must be retired in favor of explicit routes/web.php files.'
    );
});

it('verifies all registered module routes have named routes', function () {
    $routes = Route::getRoutes()->getRoutes();
    $unnamedModuleRoutes = [];

    foreach ($routes as $route) {
        $action = $route->getAction();
        $uses = $action['controller'] ?? ($action['uses'] ?? '');

        if (is_string($uses) && str_starts_with($uses, 'Modules\\')) {
            if ($route->getName() === null) {
                $unnamedModuleRoutes[] = $route->uri();
            }
        }
    }

    expect($unnamedModuleRoutes)->toBeEmpty(
        'All module routes must have an explicit route name. Unnamed routes: '.implode(', ', $unnamedModuleRoutes)
    );
});
