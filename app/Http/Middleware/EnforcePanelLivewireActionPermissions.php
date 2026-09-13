<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\TenantContext;
use App\Support\LivewireActionPermissions;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Factory\Factory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that enforces panel permissions on Livewire update requests.
 *
 * Page routes are protected by admin.can:{module}.{action} middleware, but
 * Livewire actions travel through one shared update endpoint that bypasses
 * those route guards. Without this middleware a user holding only a view
 * permission could delete or modify records by invoking component actions
 * directly.
 *
 * The middleware inspects every component in the update payload, resolves
 * its class from the signed snapshot, and checks the abilities required by
 * LivewireActionPermissions for each invoked action. It only gates panel
 * module components (Modules\* except Modules\Admin\): framework, layout,
 * and auth components keep their existing behavior, and the admin module
 * authorizes each action explicitly.
 */
class EnforcePanelLivewireActionPermissions
{
    /**
     * Create the middleware.
     *
     * @param  LivewireActionPermissions  $abilities  Convention resolver for required abilities
     */
    public function __construct(private readonly LivewireActionPermissions $abilities) {}

    /**
     * Handle an incoming Livewire update request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $components = $request->input('components');

        // Malformed payloads are rejected by Livewire itself further down.
        if (! is_array($components)) {
            return $next($request);
        }

        // Collect only the components this middleware is responsible for.
        $gated = [];

        foreach ($components as $component) {
            $resolved = $this->resolveGatedComponent($component);

            if ($resolved !== null) {
                $gated[] = $resolved;
            }
        }

        if ($gated === []) {
            return $next($request);
        }

        $actor = $this->authenticate();
        $this->establishTenantContext();

        foreach ($gated as [$class, $calls]) {
            // A request with no action calls only syncs properties, which
            // still requires at least the module view permission.
            if ($calls === []) {
                $this->assertAbilities($actor, $this->abilities->abilitiesFor($class, ''));

                continue;
            }

            foreach ($calls as $call) {
                $method = is_array($call) && is_string($call['method'] ?? null) ? $call['method'] : '';

                if ($method !== '') {
                    $this->assertAbilities($actor, $this->abilities->abilitiesFor($class, $method));
                }
            }
        }

        return $next($request);
    }

    /**
     * Resolve one payload entry to a gated component class and its calls.
     *
     * Returns null for entries that are malformed, unknown, or outside the
     * panel module scope so they are handled by their own authorization.
     *
     * @return array{0: class-string, 1: array<int, mixed>}|null
     */
    private function resolveGatedComponent(mixed $component): ?array
    {
        if (! is_array($component) || ! is_string($component['snapshot'] ?? null)) {
            return null;
        }

        $snapshot = json_decode($component['snapshot'], true);
        $name = is_array($snapshot) && is_string($snapshot['memo']['name'] ?? null)
            ? $snapshot['memo']['name']
            : null;

        if ($name === null) {
            return null;
        }

        try {
            /** @var Factory $factory */
            $factory = app('livewire.factory');
            $class = $factory->resolveComponentClass($name);
        } catch (ComponentNotFoundException) {
            // Livewire itself answers unknown components; nothing to gate.
            return null;
        }

        // Only panel module components are gated here. The admin module
        // authorizes its actions explicitly, and framework, layout, and
        // auth components must stay reachable without panel permissions.
        if (! str_starts_with($class, 'Modules\\') || str_starts_with($class, 'Modules\\Admin\\')) {
            return null;
        }

        $calls = is_array($component['calls'] ?? null) ? $component['calls'] : [];

        return [$class, $calls];
    }

    /**
     * Resolve the active panel actor or refuse anonymous access.
     *
     * Mirrors AdminAuthorize::panelActor(): impersonated sessions act as
     * the tenant user, otherwise the admin guard takes precedence.
     *
     * @throws AuthenticationException
     */
    private function authenticate(): Authenticatable
    {
        if (session()->has('impersonation.original_admin_id') && Auth::guard('web')->check()) {
            return Auth::guard('web')->user();
        }

        $actor = Auth::guard('admin')->user() ?? Auth::guard('web')->user();

        if ($actor === null) {
            throw new AuthenticationException('Unauthenticated.', ['admin', 'web']);
        }

        return $actor;
    }

    /**
     * Establish the tenant context for tenant-user requests.
     *
     * Permission lookups for tenant users depend on the active tenant, so
     * the same resolution ScopeTenant performs on panel page routes must
     * happen before any ability is checked. Admins act system-wide and
     * need no tenant context.
     */
    private function establishTenantContext(): void
    {
        $user = Auth::guard('web')->user();

        if ($user === null) {
            return;
        }

        $tenant = app(TenantContext::class)->current();

        if ($tenant === null) {
            abort(403, 'No enabled tenant is available for this account.');
        }

        if (! $user->isInTenant((int) $tenant->id)) {
            abort(403, 'You do not have access to the requested tenant.');
        }
    }

    /**
     * Abort with 403 unless every ability group is satisfied.
     *
     * All groups are required; within one group any single ability passing
     * is enough. An empty group list means no requirement applies.
     *
     * @param  array<int, array<int, string>>  $groups
     */
    private function assertAbilities(Authenticatable $actor, array $groups): void
    {
        foreach ($groups as $abilities) {
            $granted = false;

            foreach ($abilities as $ability) {
                if (Gate::forUser($actor)->check($ability)) {
                    $granted = true;

                    break;
                }
            }

            if (! $granted) {
                abort(403, 'You do not have permission to perform this action.');
            }
        }
    }
}
