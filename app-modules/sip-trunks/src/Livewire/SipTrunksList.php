<?php

declare(strict_types=1);

namespace Modules\SipTrunks\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\SipTrunks\Models\SipTrunk;
use Modules\SipTrunks\Services\SipTrunkServiceInterface;

/**
 * Admin page listing all configured SIP trunks.
 *
 * Table view with host, port, username, codecs, and status.
 */
class SipTrunksList extends BaseListComponent
{
    /** @var Collection<int, SipTrunk> */
    public Collection $trunks;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private SipTrunkServiceInterface $service;

    /** Inject the SIP-trunk service. */
    public function boot(SipTrunkServiceInterface $service): void
    {
        $this->service = $service;
    }

    /** Load the SIP trunk list when the component starts. */
    public function mount(): void
    {
        $this->load();
    }

    /** Fetch SIP trunks ordered by name. */
    private function load(): void
    {
        $this->trunks = SipTrunk::withoutGlobalScope('tenant')
            ->orderBy('name')
            ->get();
    }

    /** Delete the confirmed SIP trunk and refresh the list. */
    public function deleteTrunk(string $id): void
    {
        $trunk = SipTrunk::withoutGlobalScope('tenant')->findOrFail($id);
        $this->service->delete($trunk);
        $this->cancelTrunkDeletion();
        $this->load();
        $this->showSuccess('SIP trunk deleted.');
        $this->dispatch('notify', message: __('admin.deleted'));
    }

    /** Open the shared destructive-action confirmation for one SIP trunk. */
    public function confirmTrunkDeletion(string $id): void
    {
        $trunk = SipTrunk::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $trunk->id;
        $this->pendingDeletionName = $trunk->name;
    }

    /** Close the SIP-trunk deletion confirmation without changing the trunk. */
    public function cancelTrunkDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
