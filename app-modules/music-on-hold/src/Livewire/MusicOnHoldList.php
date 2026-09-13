<?php

declare(strict_types=1);

namespace Modules\MusicOnHold\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\MusicOnHold\Models\MusicOnHold;
use Modules\MusicOnHold\Services\MusicOnHoldService;

/**
 * Livewire component for listing music on hold entries.
 */
class MusicOnHoldList extends BaseListComponent
{
    /** @var Collection<int, MusicOnHold> */
    public Collection $holdMusics;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private MusicOnHoldService $musicOnHoldService;

    /** Inject the service that owns managed media cleanup. */
    public function boot(MusicOnHoldService $musicOnHoldService): void
    {
        $this->musicOnHoldService = $musicOnHoldService;
    }

    public function mount(): void
    {
        $this->loadHoldMusics();
    }

    /**
     * Fetch all music on hold entries ordered by name.
     */
    private function loadHoldMusics(): void
    {
        $this->holdMusics = MusicOnHold::withoutGlobalScope('tenant')
            ->orderBy('name')
            ->get();
    }

    /** Open the shared confirmation modal for a music-on-hold entry. */
    public function confirmMusicOnHoldDeletion(string $mohId): void
    {
        $music = MusicOnHold::withoutGlobalScope('tenant')->findOrFail($mohId);
        $this->pendingDeletionId = $music->id;
        $this->pendingDeletionName = $music->name;
    }

    /** Close the music-on-hold confirmation modal without deleting anything. */
    public function cancelMusicOnHoldDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName');
    }

    /** Delete the music-on-hold entry that the user confirmed. */
    public function deleteMusicOnHold(): void
    {
        $moh = MusicOnHold::withoutGlobalScope('tenant')->findOrFail($this->pendingDeletionId);
        $this->musicOnHoldService->delete($moh);
        $this->cancelMusicOnHoldDeletion();
        $this->loadHoldMusics();
        $this->showSuccess('Music on hold deleted.');
        $this->dispatch('moh-deleted');
    }
}
