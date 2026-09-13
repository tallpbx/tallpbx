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

    public function boot(PermissionService $permissionService): void
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Mount the component, auto-sync permissions, and load current state.
     *
     * Permissions are automatically synchronized to the database
     * on page load so the registry always reflects what modules
     * have declared.
     */
    public function mount(): void
    {
        $this->permissionService->syncToDatabase();
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

    public function render(): View
    {
        return view('admin::permissions-list');
    }
}
