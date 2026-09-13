<?php

declare(strict_types=1);

namespace Modules\FollowMe\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Modules\FollowMe\Models\FollowMe;
use Modules\FollowMe\Services\FollowMeService;

/**
 * Livewire component for creating and editing follow-me records.
 *
 * Manages form fields for follow-me attributes including the
 * extension, destination number, and ring timeout.
 */
class FollowMeEdit extends BaseEditComponent
{
    public string $name = '';

    public string $extension = '';

    public string $destination = '';

    public int $ringTimeout = 30;

    public ?string $followMeId = null;

    private FollowMeService $followMeService;

    /**
     * Inject the follow-me service via dependency injection.
     */
    public function boot(FollowMeService $followMeService): void
    {
        $this->followMeService = $followMeService;
    }

    /**
     * Initialize the component. Loads tenants into the dropdown and,
     * if editing, populates form fields from the existing record.
     */
    public function mount(?string $followMeId = null): void
    {
        $this->loadTenants();

        if ($followMeId !== null) {
            $this->followMeId = $followMeId;
            $record = FollowMe::withoutGlobalScope('tenant')->findOrFail($followMeId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($record);
            $this->tenantId = $record->tenant_id;
            $this->name = $record->name;
            $this->extension = $record->extension;
            $this->destination = $record->destination;
            $this->ringTimeout = $record->ring_timeout;
            $this->enabled = $record->enabled;
        }
    }

    /**
     * Determine if we are in edit mode (vs. create mode).
     */
    public function getIsEditProperty(): bool
    {
        return $this->followMeId !== null;
    }

    /**
     * Validate and save the follow-me record.
     * Creates a new record or updates an existing one inside a transaction.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'extension' => $this->extension,
            'destination' => $this->destination,
            'ring_timeout' => $this->ringTimeout,
            'enabled' => $this->enabled,
        ];

        if ($this->followMeId !== null) {
            $record = FollowMe::withoutGlobalScope('tenant')->findOrFail($this->followMeId);
            $this->followMeService->update($record, $data);
        } else {
            $this->followMeService->create($data);
        }

        $this->redirect(route('panel.follow-me.index'));

        // Queue a reloadxml so FreeSWITCH picks up the follow-me change
        ReloadFreeSwitchXml::dispatch('follow-me rule saved');
    }

    /**
     * Validation rules for the follow-me form.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'extension' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'ringTimeout' => ['required', 'integer', 'min:1', 'max:300'],
            'enabled' => ['boolean'],
        ];
    }
}
