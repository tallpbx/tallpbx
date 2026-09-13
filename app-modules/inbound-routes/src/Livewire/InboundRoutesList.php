<?php

declare(strict_types=1);

namespace Modules\InboundRoutes\Livewire;

use App\Services\TenantManager;
use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\InboundRoutes\Services\InboundRouteServiceInterface;

/**
 * Unified Livewire component that lists inbound routes with CRUD actions.
 *
 * When accessed by an admin user (admin guard), all routes across all
 * tenants are shown with full create / edit / delete capabilities.
 *
 * When accessed by a tenant user (web guard), only the current tenant's
 * routes are shown in a read-only table.
 */
class InboundRoutesList extends BaseListComponent
{
    /** @var Collection<int, InboundRoute> */
    public Collection $routes;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private InboundRouteServiceInterface $inboundRouteService;

    /**
     * Boot the component with the inbound route service.
     */
    public function boot(InboundRouteServiceInterface $inboundRouteService): void
    {
        $this->inboundRouteService = $inboundRouteService;
    }

    /**
     * Mount the component and load inbound routes.
     *
     * Admin users see all routes across all tenants. Tenant users
     * are explicitly filtered to the selected tenant from the panel
     * session because feature page requests may not have initialized
     * the global tenant scope context yet.
     */
    public function mount(): void
    {
        $this->loadRoutes();
    }

    /**
     * Delete an inbound route by its ID (admin only).
     *
     * Tenant users cannot delete routes; the tenant view does
     * not render the delete button.
     */
    public function deleteRoute(string $routeId): void
    {
        if (! $this->isAdminGuard()) {
            abort(403);
        }

        $route = InboundRoute::withoutGlobalScope('tenant')->findOrFail($routeId);
        $this->inboundRouteService->delete($route);
        $this->cancelRouteDeletion();
        $this->loadRoutes();
        $this->showSuccess('Inbound route deleted.');
        $this->dispatch('route-deleted');
    }

    /** Open the admin-only shared confirmation before deleting an inbound route. */
    public function confirmRouteDeletion(string $routeId): void
    {
        if (! $this->isAdminGuard()) {
            abort(403);
        }

        $route = InboundRoute::withoutGlobalScope('tenant')->findOrFail($routeId);
        $this->pendingDeletionId = $route->id;
        $this->pendingDeletionName = $route->name;
    }

    /** Close the inbound-route deletion confirmation without changing the route. */
    public function cancelRouteDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }

    /**
     * Load inbound routes for the active panel guard.
     */
    private function loadRoutes(): void
    {
        $this->routes = InboundRoute::withoutGlobalScope('tenant')
            ->when(! $this->isAdminGuard(), fn (Builder $query) => $query->where('tenant_id', app(TenantManager::class)->getTenantId()))
            ->orderBy('priority')
            ->orderBy('name')
            ->get();
    }

    /**
     * Render the appropriate list view based on the authenticated guard.
     *
     * Admin users see the full CRUD table; tenant users see a
     * read-only version without action buttons.
     */
    public function render(): View
    {
        return view($this->isAdminGuard()
            ? 'inbound-routes::inbound-routes-list'
            : 'inbound-routes::tenant-inbound-routes-list');
    }
}
