<?php

declare(strict_types=1);

namespace Modules\Dialplans\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Services\DialplanServiceInterface;

/**
 * Livewire component that lists dialplans with CRUD actions.
 */
class DialplansList extends BaseListComponent
{
    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private DialplanServiceInterface $dialplanService;

    /**
     * Boot the component with the dialplan service.
     */
    public function boot(DialplanServiceInterface $dialplanService): void
    {
        $this->dialplanService = $dialplanService;
    }

    /**
     * Delete a dialplan by its ID.
     */
    public function deleteDialplan(string $dialplanId): void
    {
        $dialplan = Dialplan::withoutGlobalScope('tenant')->findOrFail($dialplanId);
        $this->dialplanService->delete($dialplan);
        $this->cancelDialplanDeletion();
        $this->showSuccess('Dialplan deleted.');
        $this->dispatch('dialplan-deleted');
    }

    /** Open the shared destructive-action confirmation for one dialplan. */
    public function confirmDialplanDeletion(string $dialplanId): void
    {
        $dialplan = Dialplan::withoutGlobalScope('tenant')->findOrFail($dialplanId);
        $this->pendingDeletionId = $dialplan->id;
        $this->pendingDeletionName = $dialplan->name;
    }

    /** Close the dialplan deletion confirmation without changing the dialplan. */
    public function cancelDialplanDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }

    /** Render the paginated dialplan list. */
    public function render(): View
    {
        return view('dialplans::dialplans-list', [
            'dialplans' => Dialplan::withoutGlobalScope('tenant')
                ->withCount('details')
                ->orderBy('order')
                ->paginate(15),
        ]);
    }
}
