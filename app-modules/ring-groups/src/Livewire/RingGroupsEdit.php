<?php

declare(strict_types=1);

namespace Modules\RingGroups\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Services\RingGroupServiceInterface;

/**
 * Livewire component for creating and editing ring groups.
 *
 * Handles form validation for ring group attributes and manages
 * a dynamic list of assigned extensions. Extensions can be added
 * or removed before saving, and are persisted in a transaction.
 */
class RingGroupsEdit extends BaseEditComponent
{
    public string $name = '';

    public string $strategy = 'ring-all';

    public int $ringTimeout = 30;

    public string $description = '';

    public ?string $ringGroupId = null;

    /**
     * Dynamic list of extension entries.
     *
     * Each entry is an associative array with:
     *   - extension_uuid: string (the extension number)
     *   - position: int (auto-incremented ring order)
     *
     * @var array<int, array<string, mixed>>
     */
    public array $extensions = [];

    private RingGroupServiceInterface $ringGroupService;

    /**
     * Inject the ring group service via dependency injection.
     */
    public function boot(RingGroupServiceInterface $ringGroupService): void
    {
        $this->ringGroupService = $ringGroupService;
    }

    /**
     * Initialize the component. Loads tenants into the dropdown and,
     * if editing, populates form fields from the existing ring group.
     */
    public function mount(?string $ringGroupId = null): void
    {
        $this->loadTenants();

        if ($ringGroupId !== null) {
            $this->ringGroupId = $ringGroupId;
            $group = RingGroup::withoutGlobalScope('tenant')->with('extensions')->findOrFail($ringGroupId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($group);
            $this->tenantId = $group->tenant_id;
            $this->name = $group->name;
            $this->strategy = $group->strategy;
            $this->ringTimeout = $group->ring_timeout;
            $this->description = $group->description ?? '';
            $this->enabled = $group->enabled;

            // Load existing extensions into the dynamic list
            foreach ($group->extensions as $ext) {
                $this->extensions[] = [
                    'extension_uuid' => $ext->extension_uuid,
                    'position' => $ext->position,
                ];
            }
        } else {
            if (! $this->isAdminGuard()) {
                $this->tenantId = $this->resolveTenantId();
            }
        }
    }

    /**
     * Determine if we are in edit mode (vs. create mode).
     */
    public function getIsEditProperty(): bool
    {
        return $this->ringGroupId !== null;
    }

    /**
     * Add a new empty extension entry to the dynamic list.
     */
    public function addExtension(): void
    {
        $this->extensions[] = [
            'extension_uuid' => '',
            'position' => count($this->extensions) + 1,
        ];
    }

    /**
     * Remove an extension entry from the dynamic list by index.
     */
    public function removeExtension(int $index): void
    {
        unset($this->extensions[$index]);
        $this->extensions = array_values($this->extensions);

        // Re-number positions sequentially
        foreach ($this->extensions as $i => &$ext) {
            $ext['position'] = $i + 1;
        }
    }

    /**
     * Validate and save the ring group with its extensions.
     * Creates a new record or updates an existing one inside a transaction.
     */
    public function save(): void
    {
        $this->tenantId = $this->resolveTenantId() ?? $this->tenantId;
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'strategy' => $this->strategy,
            'ring_timeout' => $this->ringTimeout,
            'description' => $this->description ?: null,
            'enabled' => $this->enabled,
        ];

        if ($this->ringGroupId !== null) {
            $group = RingGroup::withoutGlobalScope('tenant')->findOrFail($this->ringGroupId);
            $this->assertCanAccessTenantRecord($group);
            $this->ringGroupService->update($group, $data, $this->extensions);
        } else {
            $this->ringGroupService->create($data, $this->extensions);
        }

        $this->redirect(route('panel.ring-groups.index'));

        // Queue a reloadxml so FreeSWITCH picks up the ring group change
        ReloadFreeSwitchXml::dispatch('ring group saved');
    }

    /**
     * Validation rules for the ring group form.
     */
    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'strategy' => ['required', 'string', 'in:ring-all,sequential,round-robin'],
            'ringTimeout' => ['required', 'integer', 'min:1', 'max:300'],
            'description' => ['nullable', 'string', 'max:65535'],
            'extensions' => ['nullable', 'array'],
            'extensions.*.extension_uuid' => ['required', 'string', 'max:255'],
        ];
    }
}
