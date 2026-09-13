<?php

declare(strict_types=1);

namespace Modules\CallFlows\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\CallFlows\Models\CallFlow;
use Modules\CallFlows\Services\CallFlowService;

/** Livewire component for listing and deleting call flows. */
class CallFlowsList extends BaseListComponent
{
    /** @var Collection<int, CallFlow> */
    public Collection $callFlows;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private CallFlowService $callFlowService;

    /** Inject the call-flow service. */
    public function boot(CallFlowService $callFlowService): void
    {
        $this->callFlowService = $callFlowService;
    }

    /** Load call flows when the component starts. */
    public function mount(): void
    {
        $this->loadCallFlows();
    }

    /** Fetch call flows ordered by name. */
    private function loadCallFlows(): void
    {
        $this->callFlows = CallFlow::withoutGlobalScope('tenant')
            ->orderBy('name')
            ->get();
    }

    /** Delete the confirmed call flow and refresh the list. */
    public function deleteCallFlow(string $id): void
    {
        $flow = CallFlow::withoutGlobalScope('tenant')->findOrFail($id);
        $this->callFlowService->delete($flow);
        $this->cancelCallFlowDeletion();
        $this->loadCallFlows();
        $this->showSuccess('Call flow deleted.');
        $this->dispatch('call-flow-deleted');
    }

    /** Open the shared destructive-action confirmation for one call flow. */
    public function confirmCallFlowDeletion(string $id): void
    {
        $flow = CallFlow::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $flow->id;
        $this->pendingDeletionName = $flow->name;
    }

    /** Close the call-flow deletion confirmation without changing the flow. */
    public function cancelCallFlowDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
