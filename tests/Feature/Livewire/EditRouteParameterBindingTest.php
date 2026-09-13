<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Livewire\Component;

it('keeps panel edit route parameters aligned with Livewire mount arguments', function (): void {
    $editRoutes = collect(Route::getRoutes())
        ->filter(fn ($route): bool => in_array('GET', $route->methods(), true))
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'panel/'))
        ->filter(fn ($route): bool => str_ends_with((string) $route->getName(), '.edit'));

    expect($editRoutes)->not->toBeEmpty();

    foreach ($editRoutes as $route) {
        $componentClass = $route->getAction('controller');

        if (! is_string($componentClass) || ! is_subclass_of($componentClass, Component::class)) {
            continue;
        }

        if (! method_exists($componentClass, 'mount')) {
            continue;
        }

        $mountMethod = new ReflectionMethod($componentClass, 'mount');
        $mountParameter = $mountMethod->getParameters()[0] ?? null;

        if ($mountParameter === null) {
            continue;
        }

        $mountParameterName = $mountParameter->getName();

        if (! in_array($mountParameterName, $route->parameterNames(), true)) {
            $routeParameters = implode(', ', $route->parameterNames());

            throw new RuntimeException(
                "Route [{$route->getName()}] must pass {{$mountParameterName}} to {$componentClass}::mount(); currently has [{$routeParameters}].",
            );
        }
    }
});
