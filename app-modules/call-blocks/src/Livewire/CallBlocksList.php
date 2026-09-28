<?php

declare(strict_types=1);

namespace Modules\CallBlocks\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\CallBlocks\Models\CallBlock;

/**
 * Livewire component for listing call block rules.
 */
class CallBlocksList extends BaseListComponent
{
    /** @var Collection<int, CallBlock> */
    public Collection $blocks;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    /**
     * Load the call block list when the page opens.
     */
    public function mount(): void
    {
        $this->loadBlocks();
    }

    /**
     * Fetch call blocks ordered by name. Administrators see every
     * tenant's rules; tenant users only see their own.
     */
    private function loadBlocks(): void
    {
        $query = $this->isAdminGuard()
            ? CallBlock::withoutGlobalScope('tenant')
            : CallBlock::query();

        $this->blocks = $query->orderBy('name')->get();
    }

    /**
     * Delete a call block rule and refresh the list.
     */
    public function deleteBlock(string $blockId): void
    {
        $block = CallBlock::withoutGlobalScope('tenant')->findOrFail($blockId);
        $block->delete();
        $this->cancelBlockDeletion();
        $this->loadBlocks();
        $this->showSuccess('Call block deleted.');
        $this->dispatch('block-deleted');
    }

    /** Open the shared destructive-action confirmation for one call block. */
    public function confirmBlockDeletion(string $blockId): void
    {
        $block = CallBlock::withoutGlobalScope('tenant')->findOrFail($blockId);
        $this->pendingDeletionId = $block->id;
        $this->pendingDeletionName = $block->name;
    }

    /** Close the call-block deletion confirmation without changing the rule. */
    public function cancelBlockDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
