<?php

declare(strict_types=1);

namespace Modules\OutboundRoutes\Livewire;

use App\Services\TenantManager;
use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Modules\OutboundRoutes\Models\OutboundRoute;
use Modules\OutboundRoutes\Services\OutboundRouteServiceInterface;

/**
 * Unified Livewire component that lists outbound routes with CRUD actions.
 *
 * When accessed by an admin user (admin guard), all routes across all
 * tenants are shown with full create / edit / delete capabilities.
 *
 * When accessed by a tenant user (web guard), only the current tenant's
 * routes are shown in a read-only table.
 */
class OutboundRoutesList extends BaseListComponent
{
    use WithPagination;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private OutboundRouteServiceInterface $outboundRouteService;

    /**
     * Boot the component with the outbound route service.
     */
    public function boot(OutboundRouteServiceInterface $outboundRouteService): void
    {
        $this->outboundRouteService = $outboundRouteService;
    }

    /**
     * Load outbound routes for the active panel guard.
     */
    #[Computed]
    public function routes(): LengthAwarePaginator
    {
        return OutboundRoute::withoutGlobalScope('tenant')
            ->with('gatewayRelation')
            ->when(! $this->isAdminGuard(), fn (Builder $query) => $query->where('tenant_id', app(TenantManager::class)->getTenantId()))
            ->orderBy('priority')
            ->orderBy('name')
            ->paginate(25);
    }

    /**
     * Delete an outbound route by its ID (admin only).
     *
     * Tenant users cannot delete routes; the tenant view does
     * not render the delete button.
     */
    public function deleteRoute(string $routeId): void
    {
        if (! $this->isAdminGuard()) {
            abort(403);
        }

        $route = OutboundRoute::withoutGlobalScope('tenant')->findOrFail($routeId);
        $this->assertCanAccessTenantRecord($route);
        $this->outboundRouteService->delete($route);
        $this->cancelRouteDeletion();
        $this->showSuccess('Outbound route deleted.');
        $this->dispatch('route-deleted');
    }

    /** Open the admin-only shared confirmation before deleting an outbound route. */
    public function confirmRouteDeletion(string $routeId): void
    {
        if (! $this->isAdminGuard()) {
            abort(403);
        }

        $route = OutboundRoute::withoutGlobalScope('tenant')->findOrFail($routeId);
        $this->assertCanAccessTenantRecord($route);
        $this->pendingDeletionId = $route->id;
        $this->pendingDeletionName = $route->name;
    }

    /** Close the outbound-route deletion confirmation without changing the route. */
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
            ? 'outbound-routes::outbound-routes-list'
            : 'outbound-routes::tenant-outbound-routes-list', [
                'routes' => $this->routes,
            ]);
    }
}
