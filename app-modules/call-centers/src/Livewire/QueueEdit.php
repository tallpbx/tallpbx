<?php

declare(strict_types=1);

namespace Modules\CallCenters\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\CallCenters\Models\Queue;
use Modules\CallCenters\Services\CallCenterServiceInterface;

#[Layout('layouts.app')]
class QueueEdit extends BaseEditComponent
{
    public Collection $tenants;

    public string $name = '';

    public string $strategy = 'ring-all';

    public int $timeout = 30;

    public string $musicOnHold = '';

    public ?string $queueId = null;

    private CallCenterServiceInterface $service;

    public function boot(CallCenterServiceInterface $service): void
    {
        $this->service = $service;
    }

    public function mount(?string $queueId = null): void
    {
        $this->loadTenants();
        if ($queueId !== null) {
            $this->queueId = $queueId;
            $q = Queue::withoutGlobalScope('tenant')->findOrFail($queueId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($q);
            $this->tenantId = $q->tenant_id;
            $this->name = $q->name;
            $this->strategy = $q->strategy;
            $this->timeout = $q->timeout;
            $this->musicOnHold = $q->music_on_hold ?? '';
            $this->enabled = $q->enabled;
        } else {
            if (! $this->isAdminGuard()) {
                $this->tenantId = $this->resolveTenantId();
            }
        }
    }

    public function getIsEditProperty(): bool
    {
        return $this->queueId !== null;
    }

    public function save(): void
    {
        $this->tenantId = $this->resolveTenantId() ?? $this->tenantId;
        $this->validate(['tenantId' => ['required', 'integer', 'exists:tenants,id'], 'name' => ['required', 'string', 'max:255'], 'strategy' => ['required', 'string', 'max:255'], 'timeout' => ['required', 'integer', 'min:1', 'max:600'], 'enabled' => ['boolean']]);
        $data = ['tenant_id' => $this->tenantId, 'name' => $this->name, 'strategy' => $this->strategy, 'timeout' => $this->timeout, 'music_on_hold' => $this->musicOnHold ?: null, 'enabled' => $this->enabled];
        if ($this->queueId !== null) {
            $q = Queue::withoutGlobalScope('tenant')->findOrFail($this->queueId);
            $this->assertCanAccessTenantRecord($q);
            $this->service->updateQueue($q, $data);
        } else {
            $this->service->createQueue($data);
        }
        $this->redirect(route('panel.call-centers.queues.index'));

        // Queue a reloadxml so FreeSWITCH picks up queue changes
        ReloadFreeSwitchXml::dispatch('call center queue saved');
    }
}
