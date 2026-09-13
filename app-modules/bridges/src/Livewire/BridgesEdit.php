<?php

declare(strict_types=1);

namespace Modules\Bridges\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Modules\Bridges\Models\Bridge;
use Modules\Bridges\Services\BridgeService;

/**
 * Livewire component for creating and editing call bridges.
 *
 * Handles the create and edit form for bridge destinations.
 * Works for both admin and client users — the isAdminGuard()
 * check controls tenant-scoped queries and validation rules.
 */
class BridgesEdit extends BaseEditComponent
{
    /** Name displayed for the bridge (e.g. "Conference Room A"). */
    public string $bridgeName = '';

    /** Extension number that callers dial to reach this bridge. */
    public string $destinationNumber = '';

    /** Optional PIN required to enter the bridge. */
    public string $pinNumber = '';

    /** Optional description or notes for this bridge. */
    public string $description = '';

    /** UUID of the bridge being edited (null when creating new). */
    public ?string $bridgeId = null;

    /** Service layer for bridge CRUD operations. */
    private BridgeService $bridgeService;

    /**
     * Inject the bridge service via Livewire's boot hook.
     */
    public function boot(BridgeService $bridgeService): void
    {
        $this->bridgeService = $bridgeService;
    }

    /**
     * Initialize the component state.
     *
     * When editing (bridgeId is provided), loads the existing bridge
     * data into the form fields. For admin users, uses the full query
     * scope; for client users, scopes to their tenant.
     * When creating, auto-sets the tenant from the current user
     * for client users.
     */
    public function mount(?string $bridgeId = null): void
    {
        $this->loadTenants();

        if ($bridgeId !== null) {
            $this->bridgeId = $bridgeId;
            $query = Bridge::query();
            // Admin users see all tenants' bridges; client users only see their own
            $bridge = $this->isAdminGuard()
                ? $query->withoutGlobalScope('tenant')->findOrFail($bridgeId)
                : $query->findOrFail($bridgeId);
            // Set tenantId: admin uses the bridge's tenant, client uses their own
            $this->tenantId = $this->isAdminGuard() ? $bridge->tenant_id : $this->resolveTenantId();
            $this->bridgeName = $bridge->bridge_name;
            $this->destinationNumber = $bridge->destination_number;
            $this->pinNumber = $bridge->pin_number ?? '';
            $this->description = $bridge->description ?? '';
            $this->enabled = $bridge->enabled;
        } elseif (! $this->isAdminGuard()) {
            // Client users creating a new bridge default to their own tenant
            $this->tenantId = $this->resolveTenantId();
        }
    }

    /**
     * Whether we're editing an existing bridge vs creating a new one.
     * Used in the Blade view to show "Update" or "Create" button text.
     */
    public function getIsEditProperty(): bool
    {
        return $this->bridgeId !== null;
    }

    /**
     * Save the bridge — creates or updates depending on whether
     * $this->bridgeId is set. Redirects back to the index page
     * using the guard-appropriate route.
     */
    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenantId,
            'bridge_name' => $this->bridgeName,
            'destination_number' => $this->destinationNumber,
            'pin_number' => $this->pinNumber ?: null,
            'description' => $this->description ?: null,
            'enabled' => $this->enabled,
        ];

        if ($this->bridgeId !== null) {
            $bridge = Bridge::withoutGlobalScope('tenant')->findOrFail($this->bridgeId);
            $this->bridgeService->update($bridge, $data);
        } else {
            $this->bridgeService->create($data);
        }

        // Redirect to admin.bridges.index or tenant.bridges.index based on guard
        $this->redirect(route('panel.bridges.index'));

        // Queue a reloadxml so FreeSWITCH picks up the bridge change
        ReloadFreeSwitchXml::dispatch('bridge saved');
    }

    /**
     * Validation rules for the create/edit form.
     *
     * tenantId is only required for admin users (they explicitly pick
     * a tenant from the dropdown). Client users are auto-scoped.
     */
    public function rules(): array
    {
        $rules = [
            'bridgeName' => ['required', 'string', 'max:255'],
            'destinationNumber' => ['required', 'string', 'max:255'],
            'pinNumber' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:65535'],
        ];

        // Only admin users need to select a tenant from the dropdown
        if ($this->isAdminGuard()) {
            $rules['tenantId'] = ['required', 'integer', 'exists:tenants,id'];
        }

        return $rules;
    }
}
