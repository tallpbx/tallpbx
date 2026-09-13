<?php

declare(strict_types=1);

namespace Modules\IvrMenus\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\IvrMenus\Services\IvrMenuServiceInterface;
use RuntimeException;

/**
 * Livewire component for listing IVR menus with delete capability.
 * Displays all IVR menus ordered by name and shows their
 * enabled/disabled status, timeout, and digit length settings.
 */
class IvrMenusList extends BaseListComponent
{
    /** @var Collection<int, IvrMenu> */
    public Collection $menus;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    public ?string $deleteError = null;

    private IvrMenuServiceInterface $ivrMenuService;

    /**
     * Inject the IVR menu service via dependency injection.
     */
    public function boot(IvrMenuServiceInterface $ivrMenuService): void
    {
        $this->ivrMenuService = $ivrMenuService;
    }

    /**
     * Load all IVR menus on component initialization.
     */
    public function mount(): void
    {
        $this->loadMenus();
    }

    /**
     * Fetch all IVR menus ordered by name with their options count.
     */
    private function loadMenus(): void
    {
        $this->menus = IvrMenu::withoutGlobalScope('tenant')->withCount('options')->orderBy('name')->get();
    }

    /**
     * Delete an IVR menu and its associated options.
     */
    public function deleteMenu(string $menuId): void
    {
        $menu = IvrMenu::withoutGlobalScope('tenant')->findOrFail($menuId);

        try {
            $this->ivrMenuService->delete($menu);
        } catch (RuntimeException $exception) {
            $this->deleteError = $exception->getMessage();

            return;
        }

        $this->cancelMenuDeletion();
        $this->loadMenus();
        $this->showSuccess('IVR menu deleted.');
        $this->dispatch('menu-deleted');
    }

    /** Open the shared destructive-action confirmation for one IVR menu. */
    public function confirmMenuDeletion(string $menuId): void
    {
        $menu = IvrMenu::withoutGlobalScope('tenant')->findOrFail($menuId);
        $this->pendingDeletionId = $menu->id;
        $this->pendingDeletionName = $menu->name;
        $this->deleteError = null;
    }

    /** Close the IVR-menu deletion confirmation without changing the menu. */
    public function cancelMenuDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
        $this->deleteError = null;
    }
}
