<?php

declare(strict_types=1);

namespace Modules\Conferences\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Modules\Conferences\Models\Conference;
use Modules\Conferences\Services\ConferenceService;

/**
 * Livewire component for creating and editing conference rooms.
 *
 * Manages form fields for conference attributes including the
 * FreeSWITCH profile, PIN, and maximum member limit.
 */
class ConferencesEdit extends BaseEditComponent
{
    public string $name = '';

    public string $profile = 'sample';

    public string $pin = '';

    public int $maxMembers = 100;

    public ?string $conferenceId = null;

    private ConferenceService $conferenceService;

    /**
     * Inject the conference service via dependency injection.
     */
    public function boot(ConferenceService $conferenceService): void
    {
        $this->conferenceService = $conferenceService;
    }

    /**
     * Initialize the component. Loads tenants into the dropdown and,
     * if editing, populates form fields from the existing conference.
     */
    public function mount(?string $conferenceId = null): void
    {
        $this->loadTenants();

        if ($conferenceId !== null) {
            $this->conferenceId = $conferenceId;
            $conference = Conference::withoutGlobalScope('tenant')->findOrFail($conferenceId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($conference);
            $this->tenantId = $conference->tenant_id;
            $this->name = $conference->name;
            $this->profile = $conference->profile;
            $this->pin = $conference->pin ?? '';
            $this->maxMembers = $conference->max_members;
            $this->enabled = $conference->enabled;
        }
    }

    /**
     * Determine if we are in edit mode (vs. create mode).
     */
    public function getIsEditProperty(): bool
    {
        return $this->conferenceId !== null;
    }

    /**
     * Validate and save the conference.
     * Creates a new record or updates an existing one inside a transaction.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'profile' => $this->profile,
            'pin' => $this->pin ?: null,
            'max_members' => $this->maxMembers,
            'enabled' => $this->enabled,
        ];

        if ($this->conferenceId !== null) {
            $conference = Conference::withoutGlobalScope('tenant')->findOrFail($this->conferenceId);
            $this->conferenceService->update($conference, $data);
        } else {
            $this->conferenceService->create($data);
        }

        $this->redirect(route('panel.conferences.index'));

        // Queue a reloadxml so FreeSWITCH picks up the conference change
        ReloadFreeSwitchXml::dispatch('conference saved');
    }

    /**
     * Validation rules for the conference form.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'profile' => ['required', 'string', 'max:255'],
            'pin' => ['nullable', 'string', 'max:20'],
            'maxMembers' => ['required', 'integer', 'min:1', 'max:1000'],
            'enabled' => ['boolean'],
        ];
    }
}
