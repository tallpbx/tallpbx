<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Support\LivewireActionPermissions;
use App\Support\ModuleServiceProvider;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Self-discovering authorization sweeps over the panel modules.
 *
 * These tests walk every module instead of naming them, so a new module
 * (or a renamed permission) is covered automatically:
 *
 *  1. Every component of a module that declares permissions must resolve
 *     at least one declared ability for its actions. The shared Livewire
 *     action-permission resolver fails open (no requirement) when a
 *     module's permission names do not match the module namespace — the
 *     xml-cdr bug class — and this test fails whenever that mismatch
 *     reappears.
 *
 *  2. Every permission-gated panel index route must refuse an
 *     administrator who holds no permissions at all, guaranteeing that
 *     the admin.can middleware actually denies rather than falling
 *     through to the page.
 */

/**
 * List every panel Livewire component class across the installed modules.
 *
 * Discovery covers every component, not just the *List ones: fail-open
 * authorization hides behind any component name — the fax inbox and the
 * security manager carry mutation actions without matching *List. Support
 * classes in a Livewire/Validation subdirectory are not matched. Components
 * under Modules\Admin are excluded because the shared action resolver
 * deliberately leaves the admin module to its own explicit authorization
 * checks.
 *
 * @return array<int, class-string>
 */
function moduleComponentClasses(): array
{
    $classes = [];

    foreach (glob(base_path('app-modules/*/src/Livewire/*.php')) ?: [] as $file) {
        $moduleDirectory = basename(dirname($file, 3));

        if ($moduleDirectory === 'admin') {
            continue;
        }

        $class = 'Modules\\'.Str::studly($moduleDirectory).'\\Livewire\\'.basename($file, '.php');

        if (class_exists($class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * Check whether a component's module declares panel permissions at all.
 *
 * The auth forms and the tenant landing page are deliberately reachable
 * without panel permissions, so their components legitimately resolve no
 * ability. Every other module declares its permissions in the permissions()
 * hook of its service provider — and the presence of that declaration is
 * the intent signal here, never the declared names: the very naming drift
 * this sweep hunts would corrupt a name-based check.
 */
function moduleDeclaresPermissions(string $componentClass): bool
{
    $module = explode('\\', $componentClass)[1] ?? '';
    $providerClass = "Modules\\{$module}\\Providers\\ModuleServiceProvider";

    // A module without the standard provider cannot declare permissions
    // through the shared registration path.
    if (! class_exists($providerClass) || ! method_exists($providerClass, 'permissions')) {
        return false;
    }

    // A disabled module registers no provider instance and serves no panel
    // traffic, so its components are outside this sweep.
    $provider = app()->getProvider($providerClass);

    if ($provider === null) {
        return false;
    }

    // The base class declares an empty list; only a module override means
    // the module intends permission gating. Reflection invokes the
    // protected hook directly on PHP 8.1 and later.
    $method = new ReflectionMethod($providerClass, 'permissions');

    return $method->getDeclaringClass()->getName() !== ModuleServiceProvider::class
        && $method->invoke($provider) !== [];
}

it('resolves a declared ability for every module component', function () {
    $resolver = app(LivewireActionPermissions::class);
    $failures = [];

    foreach (moduleComponentClasses() as $class) {
        // Modules that declare no permissions are deliberately open.
        if (! moduleDeclaresPermissions($class)) {
            continue;
        }

        // A delete-style action must resolve to the module delete or view
        // permission; an empty result means authorization fails open.
        if ($resolver->abilitiesFor($class, 'deleteRecord') === []) {
            $failures[] = $class;
        }
    }

    expect($failures)->toBeEmpty(
        'These components resolve no declared ability (authorization fails open): '.implode(', ', $failures),
    );
});

it('refuses permission-gated panel index routes for an administrator without permissions', function () {
    // Disable throttling so the sweep can visit every index page in one
    // test; the shared panel throttle would rate-limit the later requests.
    $this->withoutMiddleware(ThrottleRequests::class);

    $admin = Admin::factory()->create(['enabled' => true]);
    $this->actingAs($admin, 'admin');

    $failures = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName() ?? '';

        // Only plain GET index pages that carry an admin.can gate.
        if (! in_array('GET', $route->methods(), true)
            || ! str_starts_with($name, 'panel.')
            || ! str_ends_with($name, '.index')
            || str_contains($route->uri(), '{')
            || ! collect($route->middleware())->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'admin.can:'))) {
            continue;
        }

        $status = $this->get($route->uri())->getStatusCode();

        if ($status !== 403) {
            $failures[] = "{$name} ({$route->uri()}) answered {$status} instead of 403";
        }
    }

    expect($failures)->toBeEmpty(
        'These panel index routes did not deny a permission-less administrator: '.implode('; ', $failures),
    );
});
