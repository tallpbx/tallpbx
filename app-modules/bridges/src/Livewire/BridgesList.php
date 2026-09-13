<?php

declare(strict_types=1);

namespace Modules\Bridges\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Bridges\Models\Bridge;

/**
 * Livewire component for listing call bridges.
 */
class BridgesList extends BaseListComponent
{
    /** @var Collection<int, Bridge> */
    public Collection $bridges;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    /** Load the bridge list when the component starts. */
    public function mount(): void
    {
        $this->loadBridges();
    }

    /**
     * Fetch all bridges ordered by bridge_name.
     */
    private function loadBridges(): void
    {
        $query = Bridge::orderBy('bridge_name');

        $this->bridges = $this->isAdminGuard()
            ? $query->withoutGlobalScope('tenant')->get()
            : $query->get();
    }

    /**
     * Delete a bridge and refresh the list.
     */
    public function deleteBridge(string $bridgeId): void
    {
        $bridge = Bridge::withoutGlobalScope('tenant')->findOrFail($bridgeId);
        $bridge->delete();
        $this->cancelBridgeDeletion();
        $this->loadBridges();
        $this->showSuccess('Bridge deleted.');
        $this->dispatch('bridge-deleted');
    }

    /** Open the shared destructive-action confirmation for one bridge. */
    public function confirmBridgeDeletion(string $bridgeId): void
    {
        $bridge = Bridge::withoutGlobalScope('tenant')->findOrFail($bridgeId);
        $this->pendingDeletionId = $bridge->id;
        $this->pendingDeletionName = $bridge->bridge_name;
    }

    /** Close the bridge deletion confirmation without changing the bridge. */
    public function cancelBridgeDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
