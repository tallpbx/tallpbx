<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Support\LivewireActionPermissions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Self-discovering authorization sweeps over the panel modules.
 *
 * These tests walk every module instead of naming them, so a new module
 * (or a renamed permission) is covered automatically:
 *
 *  1. Every module list component must resolve at least one declared
 *     ability for its actions. The shared Livewire action-permission
 *     resolver fails open (no requirement) when a module's permission
 *     names do not match the module namespace — the xml-cdr bug class —
 *     and this test fails whenever that mismatch reappears.
 *
 *  2. Every permission-gated panel index route must refuse an
 *     administrator who holds no permissions at all, guaranteeing that
 *     the admin.can middleware actually denies rather than falling
 *     through to the page.
 */

/**
 * List every panel list component class across the installed modules.
 *
 * Components under Modules\Admin are excluded because the shared action
 * resolver deliberately leaves the admin module to its own explicit
 * authorization checks.
 *
 * @return array<int, class-string>
 */
function moduleListComponentClasses(): array
{
    $classes = [];

    foreach (glob(base_path('app-modules/*/src/Livewire/*List.php')) ?: [] as $file) {
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

it('resolves a declared ability for every module list action', function () {
    $resolver = app(LivewireActionPermissions::class);
    $failures = [];

    foreach (moduleListComponentClasses() as $class) {
        // A delete-style action must resolve to the module delete or view
        // permission; an empty result means authorization fails open.
        if ($resolver->abilitiesFor($class, 'deleteRecord') === []) {
            $failures[] = $class;
        }
    }

    expect($failures)->toBeEmpty(
        'These list components resolve no declared ability (authorization fails open): '.implode(', ', $failures),
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
