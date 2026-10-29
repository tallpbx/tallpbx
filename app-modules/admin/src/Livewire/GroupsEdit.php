<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Tenant;
use App\Services\GroupServiceInterface;
use App\Services\PermissionService;
use App\Support\PanelActor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Rule as LivewireRule;
use Livewire\Component;

/**
 * Livewire component for creating and editing groups.
 *
 * Admin-only access. Handles both create and edit modes
 * based on the presence of the groupId parameter.
 * System groups (no tenant) and tenant-scoped groups
 * are both supported. Includes permission assignment
 * via checkboxes grouped by module.
 */
#[Layout('layouts.app')]
class GroupsEdit extends Component
{
    #[LivewireRule('required|string|max:255')]
    public string $name = '';

    #[LivewireRule('nullable|string|max:1000')]
    public string $description = '';

    public ?int $tenantId = null;

    #[Locked]
    public ?string $groupId = null;

    /** @var list<int> */
    public array $selectedPermissions = [];

    /** @var array<string, list<array{id: int, name: string}>> */
    public array $permissionGroups = [];

    /** @var Collection<int, Tenant> */
    public Collection $tenants;

    private GroupServiceInterface $groupService;

    private PermissionService $permissionService;

    /**
     * Map actions on this component to required ability groups.
     *
     * @return array<string, array<int, array<int, string>>>
     */
    public static function livewireActionAbilities(): array
    {
        return [
            '' => [['admin.groups.view']],
            'save' => [['admin.groups.create', 'admin.groups.update']],
            'getIsEditProperty' => [['admin.groups.view']],
        ];
    }

    /**
     * Receive the services used to save groups and sync permissions.
     */
    public function boot(GroupServiceInterface $groupService, PermissionService $permissionService): void
    {
        $this->groupService = $groupService;
        $this->permissionService = $permissionService;
    }

    /**
     * Mount the component in create or edit mode.
     *
     * In edit mode (group UUID provided via {group} route parameter),
     * loads the existing group and populates the form fields including
     * assigned permissions. Auto-syncs permissions to database to
     * ensure the permission list is always current.
     */
    public function mount(?string $group = null): void
    {
        $this->tenants = Tenant::orderBy('name')->get();

        // Ensure permissions are synced if stale so they're available for assignment
        $this->permissionService->syncIfStale();
        $this->loadPermissionGroups();

        if ($group !== null) {
            $this->groupId = $group;
            $groupModel = Group::findOrFail($group);

            if ($groupModel->name === 'Super Administrators' && $groupModel->tenant_id === null && ! PanelActor::isSuperAdmin()) {
                abort(403, 'Unauthorized to edit Super Administrators group.');
            }

            $this->name = $groupModel->name;
            $this->description = $groupModel->description ?? '';
            $this->tenantId = $groupModel->tenant_id;
            $this->selectedPermissions = $groupModel->permissions->pluck('id')->all();
        }
    }

    /**
     * Load all permissions from the database grouped by module
     * for rendering the permission assignment checkboxes.
     */
    private function loadPermissionGroups(): void
    {
        $this->permissionGroups = Permission::query()
            ->select('id', 'name', 'module')
            ->whereNotIn('module', $this->disabledModules())
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->groupBy('module')
            ->map(fn ($items) => $items->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
            ])->all())
            ->all();
    }

    /**
     * Get disabled modules so their permissions can be hidden from assignment.
     *
     * @return list<string>
     */
    private function disabledModules(): array
    {
        return Module::query()
            ->where('enabled', false)
            ->pluck('name')
            ->all();
    }

    /**
     * Return whether we're in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->groupId !== null;
    }

    /**
     * Resolve the group model from the stored groupId.
     *
     * Uses the public groupId (which survives Livewire requests)
     * to fetch the group from the database when needed.
     */
    private function resolveGroup(): ?Group
    {
        if ($this->groupId === null) {
            return null;
        }

        return Group::findOrFail($this->groupId);
    }

    /**
     * Save the group — creates a new group or updates the existing one,
     * then syncs the assigned permissions.
     */
    public function save(): void
    {
        abort_unless(PanelActor::can($this->groupId === null ? 'admin.groups.create' : 'admin.groups.update'), 403);

        if (! PanelActor::isSuperAdmin()) {
            if ($this->groupId !== null) {
                $group = $this->resolveGroup();
                if ($group !== null && $group->name === 'Super Administrators' && $group->tenant_id === null) {
                    abort(403, 'Unauthorized to edit Super Administrators group.');
                }
            }

            if ($this->name === 'Super Administrators' && $this->tenantId === null) {
                abort(403, 'Unauthorized to modify Super Administrators group.');
            }
        }

        $this->validate($this->rules());

        $data = [
            'name' => $this->name,
            'description' => $this->description ?: null,
            'tenant_id' => $this->resolveTenantId(),
        ];

        $group = $this->resolveGroup();

        if ($group !== null) {
            $this->groupService->update($group, $data);
        } else {
            $group = $this->groupService->create($data);
        }

        // Sync the selected permissions to the group
        $this->groupService->syncPermissions($group, $this->selectedPermissions);

        $this->redirect(route('panel.groups.index'));
    }

    /**
     * Resolve the tenant ID for the group.
     *
     * Returns null for global system groups, or the selected tenant ID.
     */
    protected function resolveTenantId(): ?int
    {
        return $this->tenantId !== null ? (int) $this->tenantId : null;
    }

    /**
     * Validation rules with tenant-scoped unique constraint.
     *
     * System groups (no tenant) must have globally unique names.
     * Tenant-scoped groups must have names unique within that tenant.
     * On update, the current group's ID is ignored.
     */
    protected function rules(): array
    {
        $uniqueRule = Rule::unique('groups', 'name')
            ->where('tenant_id', $this->tenantId)
            ->ignore($this->groupId);

        return [
            'name' => ['required', 'string', 'max:255', $uniqueRule],
            'description' => ['nullable', 'string', 'max:1000'],
            'tenantId' => ['nullable', 'integer', 'exists:tenants,id'],
        ];
    }

    /**
     * Render the group create/edit form.
     */
    public function render(): View
    {
        return view('admin::groups-edit');
    }
}
