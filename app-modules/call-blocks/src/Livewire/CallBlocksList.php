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

    public function mount(): void
    {
        $this->loadBlocks();
    }

    private function loadBlocks(): void
    {
        $this->blocks = CallBlock::withoutGlobalScope('tenant')
            ->orderBy('name')
            ->get();
    }

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
