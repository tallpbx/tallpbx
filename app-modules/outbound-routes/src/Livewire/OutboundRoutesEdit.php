<?php

declare(strict_types=1);

namespace Modules\OutboundRoutes\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Modules\Gateways\Models\Gateway;
use Modules\OutboundRoutes\Models\OutboundRoute;
use Modules\OutboundRoutes\Services\OutboundRouteServiceInterface;

#[Layout('layouts.app')]
/**
 * Livewire component for creating and editing outbound routes.
 */
class OutboundRoutesEdit extends BaseEditComponent
{
    public string $name = '';

    public string $dialPattern = '';

    public string $gateway = '';

    public ?string $gatewayId = null;

    public string $callerIdName = '';

    public string $callerIdNumber = '';

    public int $priority = 100;

    public ?string $routeId = null;

    /** @var array<int, array{id: string, name: string, profile: string}> */
    public array $availableGateways = [];

    private OutboundRouteServiceInterface $outboundRouteService;

    /**
     * Boot the component with the outbound route service.
     */
    public function boot(OutboundRouteServiceInterface $outboundRouteService): void
    {
        $this->outboundRouteService = $outboundRouteService;
    }

    /**
     * Mount the component in create or edit mode.
     *
     * Loads tenant data and populates the gateway dropdown with
     * enabled gateways for the selected tenant.
     */
    public function mount(?string $routeId = null): void
    {
        $this->loadTenants();

        if ($routeId !== null) {
            $this->routeId = $routeId;
            $route = OutboundRoute::withoutGlobalScope('tenant')->findOrFail($routeId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($route);
            $this->tenantId = $route->tenant_id;
            $this->name = $route->name;
            $this->dialPattern = $route->dial_pattern;
            $this->gateway = $route->gateway ?? '';
            $this->gatewayId = $route->gateway_id;
            $this->callerIdName = $route->caller_id_name ?? '';
            $this->callerIdNumber = $route->caller_id_number ?? '';
            $this->priority = $route->priority;
            $this->enabled = $route->enabled;

            // Load gateways for the route's tenant
            $this->loadGateways($route->tenant_id);
        } elseif ($this->tenantId !== null) {
            $this->loadGateways((int) $this->tenantId);
        }
    }

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
            'dial_pattern' => $this->dialPattern,
            'gateway' => $this->gateway ?: null,
            'gateway_id' => $this->gatewayId ?: null,
            'caller_id_name' => $this->callerIdName ?: null,
            'caller_id_number' => $this->callerIdNumber ?: null,
            'priority' => $this->priority,
            'enabled' => $this->enabled,
        ];

        if ($this->routeId !== null) {
            $this->outboundRouteService->update(OutboundRoute::withoutGlobalScope('tenant')->findOrFail($this->routeId), $data);
        } else {
            $this->outboundRouteService->create($data);
        }

        $this->redirect(route('panel.outbound-routes.index'));

        // Queue a reloadxml so FreeSWITCH picks up routing changes
        ReloadFreeSwitchXml::dispatch('outbound route '.($this->routeId !== null ? 'updated' : 'created'));
    }

    public function rules(): array
    {
        $rules = [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'dialPattern' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! $this->outboundRouteService->isValidDialPattern($value)) {
                    $fail('The dial pattern is not a valid regular expression.');

                    return;
                }

                if (! $this->outboundRouteService->hasCapturingGroup((string) $value)) {
                    $fail('The dial pattern must include a capturing group because outbound routes bridge using $1.');
                }
            }],
            'priority' => ['required', 'integer', 'min:0'],
        ];

        // gatewayId must reference an existing enabled gateway in the same tenant
        if ($this->gatewayId !== null && $this->gatewayId !== '') {
            $rules['gatewayId'] = [
                'string',
                'exists:gateways,id',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $gateway = Gateway::withoutGlobalScope('tenant')->find($value);

                    if ($gateway === null || ! $gateway->enabled || $gateway->tenant_id !== $this->tenantId) {
                        $fail('The selected gateway is not available.');
                    }
                },
            ];
        }

        return $rules;
    }

    /**
     * Load available gateways for the given tenant into the dropdown.
     */
    public function loadGateways(int $tenantId): void
    {
        $this->availableGateways = Gateway::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('name')
            ->get(['id', 'name', 'profile'])
            ->toArray();
    }

    /**
     * React to tenant selection changes by reloading the gateway list.
     */
    public function updatedTenantId(int|string $value): void
    {
        $this->gatewayId = null;

        if ($value === '') {
            $this->availableGateways = [];

            return;
        }

        $this->loadGateways((int) $value);
    }
}
