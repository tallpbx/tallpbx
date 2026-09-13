<?php

declare(strict_types=1);

namespace Modules\TimeConditions\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Modules\TimeConditions\Models\TimeCondition;
use Modules\TimeConditions\Services\TimeConditionService;

/**
 * Livewire component for creating and editing time conditions.
 *
 * Manages form fields for time condition attributes including
 * weekday selection, time window, and destination routing.
 */
class TimeConditionsEdit extends BaseEditComponent
{
    public string $name = '';

    public string $timezone = '';

    public string $weekdays = 'mon,tue,wed,thu,fri';

    public string $startTime = '09:00';

    public string $endTime = '17:00';

    public string $destinationOnMatch = '';

    public string $destinationOnNoMatch = '';

    public ?string $timeConditionId = null;

    private TimeConditionService $timeConditionService;

    /**
     * Inject the time condition service via dependency injection.
     */
    public function boot(TimeConditionService $timeConditionService): void
    {
        $this->timeConditionService = $timeConditionService;
    }

    /**
     * Initialize the component. Loads tenants into the dropdown and,
     * if editing, populates form fields from the existing record.
     */
    public function mount(?string $timeConditionId = null): void
    {
        $this->loadTenants();

        if ($timeConditionId !== null) {
            $this->timeConditionId = $timeConditionId;
            $condition = TimeCondition::withoutGlobalScope('tenant')->findOrFail($timeConditionId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($condition);
            $this->tenantId = $condition->tenant_id;
            $this->name = $condition->name;
            $this->timezone = $condition->timezone ?? '';
            $this->weekdays = $condition->weekdays;
            $this->startTime = $condition->start_time;
            $this->endTime = $condition->end_time;
            $this->destinationOnMatch = $condition->destination_on_match ?? '';
            $this->destinationOnNoMatch = $condition->destination_on_no_match ?? '';
            $this->enabled = $condition->enabled;
        }
    }

    /**
     * Determine if we are in edit mode (vs. create mode).
     */
    public function getIsEditProperty(): bool
    {
        return $this->timeConditionId !== null;
    }

    /**
     * Validate and save the time condition.
     * Creates a new record or updates an existing one inside a transaction.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'timezone' => $this->timezone ?: null,
            'weekdays' => $this->weekdays,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'destination_on_match' => $this->destinationOnMatch ?: null,
            'destination_on_no_match' => $this->destinationOnNoMatch ?: null,
            'enabled' => $this->enabled,
        ];

        if ($this->timeConditionId !== null) {
            $condition = TimeCondition::withoutGlobalScope('tenant')->findOrFail($this->timeConditionId);
            $this->timeConditionService->update($condition, $data);
        } else {
            $this->timeConditionService->create($data);
        }

        $this->redirect(route('panel.time-conditions.index'));

        // Queue a reloadxml so FreeSWITCH picks up the time condition change
        ReloadFreeSwitchXml::dispatch('time condition saved');
    }

    /**
     * Validation rules for the time condition form.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'weekdays' => ['required', 'string', 'max:255'],
            'startTime' => ['required', 'string', 'max:10'],
            'endTime' => ['required', 'string', 'max:10'],
            'destinationOnMatch' => ['nullable', 'string', 'max:255'],
            'destinationOnNoMatch' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
        ];
    }
}
