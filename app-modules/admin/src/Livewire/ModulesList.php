<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Module;
use App\Services\ModuleLifecycleService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Livewire component for managing installed modules.
 *
 * Admin-only access. Modules are grouped into the nine navigation
 * categories (plan §1.6) rendered as DaisyUI accordions, each row carries
 * an enable/disable toggle, and untracked custom modules offer a
 * typed-confirmation "Delete Module" action. Built-in core modules are
 * permanent monorepo code: they can only be toggled, never uninstalled.
 */
#[Layout('layouts.app')]
class ModulesList extends Component
{
    /**
     * The canonical category order for the workbench grouping, matching the
     * nine-canonical-category contract shared with the module manifests.
     *
     * @var list<string>
     */
    public const CATEGORY_ORDER = ['core', 'accounts', 'connectivity', 'routing', 'features', 'media', 'advanced', 'monitoring', 'custom'];

    /** @var Collection<int, Module> */
    public Collection $modules;

    /** The custom module pending deletion (modal is open when set). */
    public ?string $pendingDeletionId = null;

    /** Display name of the pending deletion, shown in the modal. */
    public ?string $pendingDeletionName = null;

    /** Exact typed-confirmation phrase required by the lifecycle service. */
    public ?string $pendingDeletionPhrase = null;

    /** Typed confirmation bound to the confirmation modal input. */
    public string $confirmTypedInput = '';

    /** Error surfaced inside the confirmation dialog. */
    public ?string $deleteError = null;

    /** Whether the authenticated administrator can toggle or manage modules. */
    public bool $canManage = false;

    /**
     * Map actions on this component to required ability groups.
     *
     * @return array<string, array<int, array<int, string>>>
     */
    public static function livewireActionAbilities(): array
    {
        $viewGroup = [['admin.modules.view']];
        $manageGroup = [['admin.modules.manage']];

        return [
            '' => $viewGroup,
            'groupedModules' => $viewGroup,
            'categoryLabel' => $viewGroup,
            'confirmDeleteCustom' => $viewGroup,
            'cancelCustomDeletion' => $viewGroup,
            'deleteCustomModule' => $manageGroup,
            'toggleEnabled' => $manageGroup,
        ];
    }

    /**
     * Mount the component and initialize management permissions.
     */
    public function mount(): void
    {
        $this->canManage = (bool) Auth::guard('admin')->user()?->hasPermission('admin.modules.manage');
    }

    /**
     * Group the loaded modules by their canonical category, in the
     * navigation order every built-in manifest declares.
     *
     * @return array<string, Collection<int, Module>>
     */
    public function groupedModules(): array
    {
        $grouped = [];

        foreach (self::CATEGORY_ORDER as $category) {
            $grouped[$category] = $this->modules->filter(
                fn (Module $module): bool => $this->categoryFor($module) === $category,
            )->values();
        }

        return $grouped;
    }

    /**
     * Resolve the display label translation key for a category.
     *
     * Seven labels are reused from the root dictionary (zero-collision rule);
     * Core Administration and Custom Modules live in this module's
     * dictionary.
     */
    public function categoryLabel(string $category): string
    {
        return match ($category) {
            'core' => (string) __('admin.modules_category_core'),
            'accounts' => (string) __('admin.pbx_accounts'),
            'connectivity' => (string) __('admin.pbx_connectivity'),
            'routing' => (string) __('admin.pbx_routing'),
            'features' => (string) __('admin.pbx_features'),
            'media' => (string) __('admin.pbx_media'),
            'advanced' => (string) __('admin.pbx_advanced'),
            'monitoring' => (string) __('admin.pbx_monitoring'),
            'custom' => (string) __('admin.modules_category_custom'),
            default => (string) __('admin.pbx_advanced'),
        };
    }

    /**
     * Open the delete confirmation dialog for a custom module.
     */
    public function confirmDeleteCustom(string $moduleId): void
    {
        $this->ensureManagePermission();

        $module = Module::findOrFail($moduleId);

        if ($module->source !== 'custom') {
            return;
        }

        $this->pendingDeletionId = $module->id;
        $this->pendingDeletionName = $module->display_name;
        $this->pendingDeletionPhrase = 'UNINSTALL '.$module->name;
        $this->confirmTypedInput = '';
        $this->deleteError = null;
    }

    /**
     * Close the delete confirmation dialog without deleting.
     */
    public function cancelCustomDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = null;
        $this->pendingDeletionPhrase = null;
        $this->confirmTypedInput = '';
        $this->deleteError = null;
    }

    /**
     * Permanently delete a custom module through the lifecycle service.
     *
     * The typed confirmation phrase must match the exact UNINSTALL {name}
     * contract the lifecycle service enforces.
     */
    public function deleteCustomModule(string $moduleId, ?string $confirmation = null): void
    {
        $this->ensureManagePermission();

        $module = Module::findOrFail($moduleId);

        // Only untracked custom modules are deletable here; core modules
        // are permanent and vendor modules are removed via Composer.
        if ($module->source !== 'custom') {
            $this->deleteError = (string) __('admin.modules_delete_not_custom');

            return;
        }

        $confirmation ??= $this->confirmTypedInput;

        try {
            app(ModuleLifecycleService::class)->uninstall($module->name, $confirmation);
        } catch (ValidationException $exception) {
            $this->deleteError = collect($exception->errors())->flatten()->first()
                ?? (string) __('admin.modules_delete_failed');

            return;
        }

        $this->cancelCustomDeletion();
        $this->loadModules();
    }

    /**
     * Toggle the enabled status of a module.
     *
     * Required modules cannot be disabled; protected modules are only
     * shielded from uninstall, not from disabling (plan §7 Step 1.3).
     */
    public function toggleEnabled(string $moduleId): void
    {
        $this->ensureManagePermission();

        $module = Module::findOrFail($moduleId);

        if ($module->status === Module::StatusUninstalled) {
            return;
        }

        // Required modules cannot be disabled
        if ($module->required && $module->enabled) {
            return;
        }

        $enabled = ! $module->enabled;

        $module->update([
            'enabled' => $enabled,
            'status' => $enabled ? Module::StatusEnabled : Module::StatusDisabled,
        ]);

        Artisan::call('optimize:clear');

        $this->loadModules();
        $this->dispatch('module-toggled');
    }

    /**
     * Refuse lifecycle or toggle actions without the module management permission.
     */
    protected function ensureManagePermission(): void
    {
        $actor = Auth::guard('admin')->user();

        abort_unless($actor !== null && $actor->hasPermission('admin.modules.manage'), 403);
    }

    /**
     * Render the module registry management screen.
     */
    public function render(): View
    {
        $this->loadModules();

        return view('admin::modules-list', ['groupedModules' => $this->groupedModules()]);
    }

    /**
     * Load all modules from the database.
     */
    private function loadModules(): void
    {
        $this->modules = Module::orderBy('priority')->orderBy('name')->get();
    }

    /**
     * Resolve the canonical category for a module, falling back for legacy
     * rows that predate the category column.
     */
    private function categoryFor(Module $module): string
    {
        if ($module->category !== null && $module->category !== '') {
            return $module->category;
        }

        return $module->source === 'custom' ? 'custom' : 'advanced';
    }
}
