<?php

declare(strict_types=1);

namespace Modules\CallBlocks\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Modules\CallBlocks\Models\CallBlock;
use Modules\CallBlocks\Services\CallBlockServiceInterface;

/**
 * Livewire component for creating and editing call block rules.
 */
class CallBlocksEdit extends BaseEditComponent
{
    public string $name = '';

    public string $callerIdNumber = '';

    public string $description = '';

    public ?string $blockId = null;

    private CallBlockServiceInterface $callBlockService;

    /**
     * Inject the call block service used by this component.
     */
    public function boot(CallBlockServiceInterface $callBlockService): void
    {
        $this->callBlockService = $callBlockService;
    }

    /**
     * Open the create form, or load the given rule for editing when a
     * record id is supplied (tenant users may only open their own).
     */
    public function mount(?string $blockId = null): void
    {
        $this->loadTenants();

        if ($blockId !== null) {
            $this->blockId = $blockId;
            $block = CallBlock::withoutGlobalScope('tenant')->findOrFail($blockId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($block);
            $this->tenantId = $block->tenant_id;
            $this->name = $block->name;
            $this->callerIdNumber = $block->caller_id_number;
            $this->description = $block->description ?? '';
            $this->enabled = $block->enabled;
        }
    }

    /**
     * Whether the form is editing an existing rule rather than
     * creating a new one.
     */
    public function getIsEditProperty(): bool
    {
        return $this->blockId !== null;
    }

    /**
     * Validate the form, store the rule, and ask FreeSWITCH to reload
     * its configuration so the change takes effect.
     */
    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'caller_id_number' => $this->callerIdNumber,
            'description' => $this->description ?: null,
            'enabled' => $this->enabled,
        ];

        if ($this->blockId !== null) {
            $block = CallBlock::withoutGlobalScope('tenant')->findOrFail($this->blockId);
            $this->callBlockService->update($block, $data);
        } else {
            $this->callBlockService->create($data);
        }

        $this->redirect(route('panel.call-blocks.index'));

        // Queue a reloadxml so FreeSWITCH picks up the call block change
        ReloadFreeSwitchXml::dispatch('call block saved');
    }

    /**
     * Validation rules for the call block form.
     */
    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'callerIdNumber' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
        ];
    }
}
