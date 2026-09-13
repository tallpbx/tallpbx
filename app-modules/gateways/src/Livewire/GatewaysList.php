<?php

declare(strict_types=1);

namespace Modules\Gateways\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Gateways\Models\Gateway;
use Modules\Gateways\Services\GatewayServiceInterface;

/**
 * Livewire component that lists gateways with CRUD actions.
 */
class GatewaysList extends BaseListComponent
{
    /** @var Collection<int, Gateway> */
    public Collection $gateways;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private GatewayServiceInterface $gatewayService;

    /**
     * Boot the component with the gateway service.
     */
    public function boot(GatewayServiceInterface $gatewayService): void
    {
        $this->gatewayService = $gatewayService;
    }

    /**
     * Mount the component and load gateways.
     */
    public function mount(): void
    {
        $this->loadGateways();
    }

    /**
     * Load all gateways ordered by name.
     */
    private function loadGateways(): void
    {
        $this->gateways = Gateway::withoutGlobalScope('tenant')->orderBy('name')->get();
    }

    /**
     * Delete a gateway by its ID.
     */
    public function deleteGateway(string $gatewayId): void
    {
        $gateway = Gateway::withoutGlobalScope('tenant')->findOrFail($gatewayId);
        $this->gatewayService->delete($gateway);
        $this->cancelGatewayDeletion();
        $this->loadGateways();
        $this->showSuccess('Gateway deleted.');
        $this->dispatch('gateway-deleted');
    }

    /** Open the shared destructive-action confirmation for one gateway. */
    public function confirmGatewayDeletion(string $gatewayId): void
    {
        $gateway = Gateway::withoutGlobalScope('tenant')->findOrFail($gatewayId);
        $this->pendingDeletionId = $gateway->id;
        $this->pendingDeletionName = $gateway->name;
    }

    /** Close the gateway deletion confirmation without changing the gateway. */
    public function cancelGatewayDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
