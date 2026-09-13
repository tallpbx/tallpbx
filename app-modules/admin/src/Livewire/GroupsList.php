<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Group;
use App\Models\Tenant;
use App\Services\GroupServiceInterface;
use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Livewire component that lists groups with CRUD actions.
 *
 * Admin-only access. Shows system groups by default;
 * optionally filters by tenant. Displays user and
 * permission counts for each group.
 */
#[Layout('layouts.app')]
class GroupsList extends Component
{
    use HasOperationalFeedback;

    /** @var Collection<int, Group> */
    public Collection $groups;

    public ?int $tenantId = null;

    /** @var list<Tenant> */
    public Collection $tenants;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    public ?string $deleteError = null;

    private GroupServiceInterface $groupService;

    public function boot(GroupServiceInterface $groupService): void
    {
        $this->groupService = $groupService;
    }

    public function mount(): void
    {
        $this->tenants = Tenant::orderBy('name')->get();
        $this->loadGroups();
    }

    /**
     * Load groups based on current filter.
     */
    private function loadGroups(): void
    {
        if ($this->tenantId !== null) {
            $this->groups = $this->groupService->getByTenant($this->tenantId);
        } else {
            $this->groups = $this->groupService->getSystem();
        }

        $this->groups->loadCount(['users', 'permissions']);
    }

    /**
     * Filter groups by tenant.
     *
     * @param  array{tenant_id?: int|null}  $filter
     */
    public function setFilter(array $filter): void
    {
        $this->tenantId = $filter['tenant_id'] ?? null;
        $this->loadGroups();
    }

    /** Open the shared confirmation modal for one group. */
    public function confirmGroupDeletion(string $groupId): void
    {
        $group = Group::findOrFail($groupId);
        $this->pendingDeletionId = $group->id;
        $this->pendingDeletionName = $group->name;
        $this->deleteError = null;
    }

    /** Close the group deletion confirmation without deleting. */
    public function cancelGroupDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName', 'deleteError');
    }

    /** Delete the group that the user confirmed, keeping expected failures in the modal. */
    public function deleteGroup(): void
    {
        if ($this->pendingDeletionId === null) {
            return;
        }

        $group = Group::findOrFail($this->pendingDeletionId);

        try {
            $this->groupService->delete($group);
        } catch (RuntimeException $exception) {
            $this->deleteError = 'Group could not be deleted. '.$exception->getMessage();

            return;
        }

        $this->cancelGroupDeletion();
        $this->loadGroups();
        $this->showSuccess('Group deleted.');
        $this->dispatch('group-deleted');
    }

    public function render(): View
    {
        return view('admin::groups-list');
    }
}
