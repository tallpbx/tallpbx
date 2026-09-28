<?php

declare(strict_types=1);

namespace Modules\SipTrunks\Livewire;

use App\Support\BaseEditComponent;
use Modules\SipTrunks\Models\SipTrunk;
use Modules\SipTrunks\Services\SipTrunkServiceInterface;

/**
 * Create/edit form for SIP trunk configurations.
 *
 * Fields for host, port, credentials, codecs, and enabled status.
 */
class SipTrunksEdit extends BaseEditComponent
{
    public string $name = '';

    public string $host = '';

    public int $port = 5060;

    public string $username = '';

    public string $password = '';

    public string $codecs = '';

    public ?string $trunkId = null;

    private SipTrunkServiceInterface $service;

    /**
     * Inject the SIP trunk service used by this component.
     */
    public function boot(SipTrunkServiceInterface $service): void
    {
        $this->service = $service;
    }

    /**
     * Open the create form, or load the given trunk for editing when a
     * record id is supplied (tenant users may only open their own).
     */
    public function mount(?string $trunkId = null): void
    {
        $this->loadTenants();

        if ($trunkId === null) {
            return;
        }

        $trunk = SipTrunk::withoutGlobalScope('tenant')->findOrFail($trunkId);
        // Tenant users may only open records of their active tenant.
        $this->assertCanAccessTenantRecord($trunk);
        $this->trunkId = $trunk->id;
        $this->tenantId = $trunk->tenant_id;
        $this->name = $trunk->name;
        $this->host = $trunk->host;
        $this->port = $trunk->port;
        $this->username = $trunk->username ?? '';
        $this->password = $trunk->password ?? '';
        $this->codecs = $trunk->codecs ?? '';
        $this->enabled = $trunk->enabled;
    }

    /**
     * Whether the form is editing an existing trunk rather than
     * creating a new one.
     */
    public function getIsEditProperty(): bool
    {
        return $this->trunkId !== null;
    }

    /**
     * Validate the form and create or update the SIP trunk.
     */
    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username ?: null,
            'password' => $this->password ?: null,
            'codecs' => $this->codecs ?: null,
            'enabled' => $this->enabled,
        ];

        if ($this->isEdit) {
            $trunk = SipTrunk::withoutGlobalScope('tenant')->findOrFail($this->trunkId);
            $this->service->update($trunk, $data);
        } else {
            $this->service->create($data);
        }

        $this->redirect(route('panel.sip-trunks.index'), navigate: true);
    }

    /**
     * Validation rules for the SIP trunk form.
     */
    public function rules(): array
    {
        return [
            'tenantId' => 'required|exists:tenants,id',
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'codecs' => 'nullable|string|max:255',
            'enabled' => 'boolean',
        ];
    }
}
