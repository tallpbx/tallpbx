<?php

declare(strict_types=1);

namespace Modules\Acl\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Acl\Models\AccessControl;
use Modules\Acl\Services\AccessControlServiceInterface;

/**
 * Livewire component that lists access control rules with CRUD actions.
 */
class AclList extends BaseListComponent
{
    /** @var Collection<int, AccessControl> */
    public Collection $rules;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private AccessControlServiceInterface $accessControlService;

    /**
     * Inject the access control service.
     */
    public function boot(AccessControlServiceInterface $accessControlService): void
    {
        $this->accessControlService = $accessControlService;
    }

    /**
     * Mount the component and load access control rules.
     */
    public function mount(): void
    {
        $this->loadRules();
    }

    /**
     * Load all access control rules.
     */
    private function loadRules(): void
    {
        $query = $this->isAdminGuard()
            ? AccessControl::withoutGlobalScope('tenant')
            : AccessControl::query();

        $this->rules = $query->withCount('nodes')->orderBy('name')->get();
    }

    /**
     * Delete an access control rule by its ID.
     */
    public function deleteRule(string $ruleId): void
    {
        $rule = AccessControl::withoutGlobalScope('tenant')->findOrFail($ruleId);
        $this->assertCanAccessTenantRecord($rule);
        $this->accessControlService->delete($rule);
        $this->cancelRuleDeletion();
        $this->loadRules();
        $this->showSuccess('Access control rule deleted.');
        $this->dispatch('rule-deleted');
    }

    /** Open the shared deletion confirmation for an access control rule. */
    public function confirmRuleDeletion(string $ruleId): void
    {
        $rule = AccessControl::withoutGlobalScope('tenant')->findOrFail($ruleId);
        $this->assertCanAccessTenantRecord($rule);
        $this->pendingDeletionId = $rule->id;
        $this->pendingDeletionName = $rule->name;
    }

    /** Close the access-control deletion confirmation. */
    public function cancelRuleDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
