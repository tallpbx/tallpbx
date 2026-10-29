<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Services\PermissionService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Read-only reference of all registered permissions grouped by module.
 *
 * Permissions are defined in code by each module's ServiceProvider
 * and auto-synced to the database on page load. This page exists
 * purely as documentation — no manual management is needed.
 * Assign permissions to groups via the Groups edit page.
 */
#[Layout('layouts.app')]
class PermissionsList extends Component
{
    /**
     * Permissions grouped by module, each with name and description.
     *
     * @var array<string, list<array{name: string, description: string|null}>>
     */
    public array $permissions = [];

    /** @var list<string> */
    public array $modules = [];

    private PermissionService $permissionService;

    /**
     * Map actions on this component to required ability groups.
     *
     * @return array<string, array<int, array<int, string>>>
     */
    public static function livewireActionAbilities(): array
    {
        return [
            '' => [['admin.permissions.view']],
        ];
    }

    /**
     * Inject the permission service used by this component.
     */
    public function boot(PermissionService $permissionService): void
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Mount the component, ensure permissions are synchronized if stale, and load current state.
     */
    public function mount(): void
    {
        $this->permissionService->syncIfStale();
        $this->loadPermissions();
    }

    /**
     * Load permissions grouped by module from the service.
     */
    private function loadPermissions(): void
    {
        $this->permissions = $this->permissionService->grouped();
        $this->modules = array_keys($this->permissions);
    }

    /**
     * Render the permissions list view.
     */
    public function render(): View
    {
        return view('admin::permissions-list');
    }
}
