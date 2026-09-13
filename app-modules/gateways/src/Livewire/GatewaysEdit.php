<?php

declare(strict_types=1);

namespace Modules\Gateways\Livewire;

use App\Jobs\ManageGateway;
use App\Jobs\ReloadSofiaProfile;
use App\Support\BaseEditComponent;
use Modules\Gateways\Models\Gateway;
use Modules\Gateways\Services\GatewayServiceInterface;

/**
 * Livewire component for creating and editing gateways.
 */
class GatewaysEdit extends BaseEditComponent
{
    public string $name = '';

    public string $host = '';

    public string $port = '5060';

    public string $username = '';

    public string $password = '';

    public bool $register = true;

    public string $profile = 'external';

    public ?string $gatewayId = null;

    private GatewayServiceInterface $gatewayService;

    /**
     * Boot the component with the gateway service.
     */
    public function boot(GatewayServiceInterface $gatewayService): void
    {
        $this->gatewayService = $gatewayService;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?string $gatewayId = null): void
    {
        $this->loadTenants();

        if ($gatewayId !== null) {
            $this->gatewayId = $gatewayId;
            $gateway = Gateway::withoutGlobalScope('tenant')->findOrFail($gatewayId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($gateway);
            $this->tenantId = $gateway->tenant_id;
            $this->name = $gateway->name;
            $this->host = $gateway->host;
            $this->port = (string) $gateway->port;
            $this->username = $gateway->username ?? '';
            $this->register = $gateway->register;
            $this->profile = $gateway->profile ?? 'external';
            $this->enabled = $gateway->enabled;
        }
    }

    public function getIsEditProperty(): bool
    {
        return $this->gatewayId !== null;
    }

    public function save(): void
    {
        $this->validate($this->rules());

        // Reload original gateway state during save because private Livewire properties do not persist between requests.
        $existingGateway = $this->gatewayId !== null
            ? Gateway::withoutGlobalScope('tenant')->findOrFail($this->gatewayId)
            : null;
        $originalEnabled = $existingGateway?->enabled ?? false;
        $originalProfile = $existingGateway?->profile ?? 'external';

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'host' => $this->host,
            'port' => (int) $this->port,
            'username' => $this->username ?: null,
            'password' => $this->password ?: null,
            'register' => $this->register,
            'profile' => $this->profile ?: 'external',
            'enabled' => $this->enabled,
        ];

        if ($existingGateway !== null) {
            if (empty($this->password)) {
                unset($data['password']);
            }
            $savedGateway = $this->gatewayService->update($existingGateway, $data);
        } else {
            $savedGateway = $this->gatewayService->create($data);
        }

        $this->redirect(route('panel.gateways.index'));

        $profile = $this->profile ?: 'external';
        $trigger = 'gateway '.($this->gatewayId !== null ? 'updated' : 'created');

        // Use targeted Sofia gateway/profile operations so trunk changes apply without forcing unrelated profiles to reload.
        if ($this->gatewayId === null && $this->enabled) {
            ManageGateway::dispatch($profile, $savedGateway->id, ManageGateway::ACTION_START, $trigger);
        } elseif ($this->gatewayId !== null && $profile !== $originalProfile) {
            ManageGateway::dispatch($originalProfile, $savedGateway->id, ManageGateway::ACTION_KILL, $trigger);
            if ($this->enabled) {
                ManageGateway::dispatch($profile, $savedGateway->id, ManageGateway::ACTION_START, $trigger);
            }
        } elseif ($this->gatewayId !== null && $this->enabled !== $originalEnabled) {
            $action = $this->enabled ? ManageGateway::ACTION_START : ManageGateway::ACTION_KILL;
            ManageGateway::dispatch($profile, $savedGateway->id, $action, $trigger);
        }

        ReloadSofiaProfile::dispatch($profile, $trigger);
    }

    protected function rules(): array
    {
        return [
            'tenantId' => ['required', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'numeric', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }
}
