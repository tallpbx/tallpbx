<?php

declare(strict_types=1);

namespace Modules\InboundRoutes\Livewire;

use App\Services\TenantManager;
use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
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
    use WithPagination;

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
     * Load inbound routes for the active panel guard.
     */
    #[Computed]
    public function routes(): LengthAwarePaginator
    {
        return InboundRoute::withoutGlobalScope('tenant')
            ->when(! $this->isAdminGuard(), fn (Builder $query) => $query->where('tenant_id', app(TenantManager::class)->getTenantId()))
            ->orderBy('priority')
            ->orderBy('name')
            ->paginate(25);
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
        $this->assertCanAccessTenantRecord($route);
        $this->inboundRouteService->delete($route);
        $this->cancelRouteDeletion();
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
        $this->assertCanAccessTenantRecord($route);
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
     * Render the appropriate list view based on the authenticated guard.
     *
     * Admin users see the full CRUD table; tenant users see a
     * read-only version without action buttons.
     */
    public function render(): View
    {
        return view($this->isAdminGuard()
            ? 'inbound-routes::inbound-routes-list'
            : 'inbound-routes::tenant-inbound-routes-list', [
                'routes' => $this->routes,
            ]);
    }
}
