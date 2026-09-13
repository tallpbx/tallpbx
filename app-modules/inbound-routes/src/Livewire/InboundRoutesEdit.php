<?php

declare(strict_types=1);

namespace Modules\InboundRoutes\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Services\DestinationResolver;
use App\Support\BaseEditComponent;
use App\Support\FreeSwitchApplications;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\InboundRoutes\Services\InboundRouteServiceInterface;

#[Layout('layouts.app')]
/**
 * Livewire component for creating and editing inbound routes.
 */
class InboundRoutesEdit extends BaseEditComponent
{
    public string $name = '';

    public string $destinationNumber = '';

    public string $action = '';

    public string $actionData = '';

    public int $priority = 100;

    public ?string $routeId = null;

    private InboundRouteServiceInterface $inboundRouteService;

    /**
     * Boot the component with the inbound route service.
     */
    public function boot(InboundRouteServiceInterface $inboundRouteService): void
    {
        $this->inboundRouteService = $inboundRouteService;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?string $routeId = null): void
    {
        $this->loadTenants();

        if ($routeId !== null) {
            $this->routeId = $routeId;
            $route = InboundRoute::withoutGlobalScope('tenant')->findOrFail($routeId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($route);
            $this->tenantId = $route->tenant_id;
            $this->name = $route->name;
            $this->destinationNumber = $route->destination_number;
            $this->action = $route->action;
            $this->actionData = $route->action_data ?? '';
            $this->priority = $route->priority;
            $this->enabled = $route->enabled;
        }
    }

    /**
     * Return whether we're in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->routeId !== null;
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'destination_number' => $this->destinationNumber,
            'action' => $this->action,
            'action_data' => $this->actionData ?: null,
            'priority' => $this->priority,
            'enabled' => $this->enabled,
        ];

        if ($this->routeId !== null) {
            $this->inboundRouteService->update(InboundRoute::withoutGlobalScope('tenant')->findOrFail($this->routeId), $data);
        } else {
            $this->inboundRouteService->create($data);
        }

        $this->redirect(route('panel.inbound-routes.index'));

        // Queue a reloadxml so FreeSWITCH picks up routing changes
        ReloadFreeSwitchXml::dispatch('inbound route '.($this->routeId !== null ? 'updated' : 'created'));
    }

    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'destinationNumber' => ['required', 'string', 'max:255'],
            'action' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        $fail("The {$attribute} '{$value}' is not a recognized FreeSWITCH application or destination type.");

                        return;
                    }

                    if (
                        ! FreeSwitchApplications::isAllowed($value)
                        && ! in_array($value, DestinationResolver::routeSelectableKinds(), true)
                    ) {
                        $fail("The {$attribute} '{$value}' is not a recognized FreeSWITCH application.");
                    }
                },
            ],
            'actionData' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (in_array($this->action, DestinationResolver::routeSelectableKinds(), true) && blank($value)) {
                        $fail('The action data field is required for typed destinations.');
                    }
                },
            ],
            'priority' => ['required', 'integer', 'min:0'],
        ];
    }
}
