<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Services\PermissionService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);

    // Grant every registered permission so the admin can access all routes
    $group = Group::factory()->system()->create(['name' => 'Super Admin']);
    $permissionService = app(PermissionService::class);
    foreach ($permissionService->all() as $name) {
        $module = explode('.', $name)[0] ?? 'admin';
        $perm = Permission::factory()->create(['name' => $name, 'module' => $module]);
        $group->permissions()->attach($perm);
    }
    $this->admin->groups()->syncWithoutDetaching([$group->id]);
});

it('requires web middleware on every panel route', function () {
    $panelRoutes = collect(Route::getRoutes()->getRoutesByName())
        ->filter(fn ($route) => str_starts_with($route->getName(), 'panel.'))
        ->filter(fn ($route) => $route->getName() !== 'panel.');

    expect($panelRoutes)->not->toBeEmpty('No panel routes found.');

    foreach ($panelRoutes as $name => $route) {
        $middleware = (array) ($route->getAction()['middleware'] ?? []);
        expect(in_array('web', $middleware, true))
            ->toBeTrue("Panel route [{$name}] is missing the 'web' middleware — without it the session never starts and the guard returns null.");
    }
});

it('ensures edit routes have correctly formed parameter braces', function () {
    // Convention-based edit routes should produce URIs like /bridges/{bridge}/edit,
    // NOT /bridges/{bridge/edit (which was a bug in ModuleServiceProvider).
    // Singleton forms (no mount parameter) are skipped — they intentionally
    // use a flat URL like /smtp-connector without an ID segment.
    $editRoutes = collect(Route::getRoutes()->getRoutesByName())
        ->filter(fn ($route) => str_ends_with($route->getName(), '.edit'))
        ->filter(function ($route) {
            $componentClass = $route->getAction('controller');
            if (! is_string($componentClass) || ! class_exists($componentClass) || ! method_exists($componentClass, 'mount')) {
                return true; // non-component routes — still check them
            }
            $method = new ReflectionMethod($componentClass, 'mount');
            $firstParam = $method->getParameters()[0] ?? null;

            // Skip singleton forms that have no mount parameter
            return $firstParam !== null;
        });

    expect($editRoutes)->not->toBeEmpty('No edit routes found.');

    foreach ($editRoutes as $name => $route) {
        // The route parameter brace must close before /edit, not after it.
        // Valid:   /module/{param}/edit
        // Invalid: /module/{param/edit
        expect($route->uri())
            ->toMatch('/\{[^}]+\}\/edit/', "Edit route [{$name}] has a malformed parameter brace — the closing }} must precede /edit.");
    }
});

it('generates every static panel route URL', function () {
    $panelRoutes = collect(Route::getRoutes()->getRoutesByName())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->filter(fn ($route) => ! str_contains($route->uri(), '{'))
        ->filter(fn ($route) => str_starts_with($route->getName(), 'panel.'))
        ->filter(fn ($route) => $route->getName() !== 'panel.');

    expect($panelRoutes)->not->toBeEmpty('No static panel routes found.');

    foreach ($panelRoutes as $name => $route) {
        expect(route($name))
            ->toBeString()
            ->toContain($route->uri());
    }
});

it('serves representative panel routes through the full HTTP stack', function () {
    // Disable throttling so we can hit all routes in a single test
    $this->withoutMiddleware(ThrottleRequests::class);

    $routes = [
        'panel.dashboard',
        'panel.modules.index',
        'panel.extensions.index',
        'panel.extensions.create',
        'panel.sip-profiles.index',
        'panel.inbound-routes.index',
        'panel.outbound-routes.index',
        'panel.settings.index',
    ];

    foreach ($routes as $name) {
        actingAs($this->admin, 'admin')
            ->get(route($name))
            ->assertOk();
    }
});

it('has no duplicate named routes for inbound and outbound route indexes', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName());

    $inbound = $routes->filter(fn ($route) => $route->getName() === 'panel.inbound-routes.index');
    $outbound = $routes->filter(fn ($route) => $route->getName() === 'panel.outbound-routes.index');

    expect($inbound)->toHaveCount(1, 'Expected exactly one panel.inbound-routes.index route.');
    expect($outbound)->toHaveCount(1, 'Expected exactly one panel.outbound-routes.index route.');

    // Verify the resolved action is a Livewire component class, not a closure
    $inboundAction = $inbound->first()->getAction('uses');
    $outboundAction = $outbound->first()->getAction('uses');

    expect($inboundAction)->toBeString()->toContain('InboundRoutesList');
    expect($outboundAction)->toBeString()->toContain('OutboundRoutesList');
    expect($inboundAction)->not->toContain('Closure');
    expect($outboundAction)->not->toContain('Closure');
});

it('registers each backup panel route exactly once', function (): void {
    $backupRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'panel.backups.'));

    expect($backupRoutes)->toHaveCount(4)
        ->and($backupRoutes->pluck('action.as')->sort()->values()->all())->toBe([
            'panel.backups.create',
            'panel.backups.edit',
            'panel.backups.index',
            'panel.backups.restore',
        ]);
});
