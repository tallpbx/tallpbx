<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Module;
use App\Services\ModuleLifecycleService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Livewire component for managing installed modules.
 *
 * Admin-only access. Lists all modules with enable/disable
 * toggle. Required and protected modules cannot be disabled.
 */
#[Layout('layouts.app')]
class ModulesList extends Component
{
    /** @var Collection<int, Module> */
    public Collection $modules;

    public ?string $pendingUninstallModuleId = null;

    public string $uninstallConfirmation = '';

    /** @var array{can_uninstall?: bool, confirmation?: string, items?: array<int, string>, reason?: string|null} */
    public array $uninstallPreview = [];

    private ModuleLifecycleService $moduleLifecycle;

    /**
     * Receive the service that handles uninstall and reinstall workflows.
     */
    public function boot(ModuleLifecycleService $moduleLifecycle): void
    {
        $this->moduleLifecycle = $moduleLifecycle;
    }

    /**
     * Load modules when the page first opens.
     */
    public function mount(): void
    {
        $this->loadModules();
    }

    /**
     * Load all modules from the database.
     */
    private function loadModules(): void
    {
        $this->modules = Module::orderBy('priority')->orderBy('name')->get();
    }

    /**
     * Toggle the enabled status of a module.
     *
     * Required modules cannot be disabled.
     */
    public function toggleEnabled(string $moduleId): void
    {
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
     * Prepare the destructive uninstall confirmation state for a module.
     */
    public function prepareUninstall(string $moduleId): void
    {
        $module = Module::findOrFail($moduleId);

        $this->resetErrorBag('uninstallConfirmation');
        $this->pendingUninstallModuleId = $module->id;
        $this->uninstallConfirmation = '';
        $this->uninstallPreview = $this->moduleLifecycle->previewUninstall($module);
    }

    /**
     * Cancel the pending uninstall confirmation flow.
     */
    public function cancelUninstall(): void
    {
        $this->pendingUninstallModuleId = null;
        $this->uninstallConfirmation = '';
        $this->uninstallPreview = [];
        $this->resetErrorBag('uninstallConfirmation');
    }

    /**
     * Destructively uninstall a module after exact confirmation.
     */
    public function uninstallModule(): void
    {
        if ($this->pendingUninstallModuleId === null) {
            return;
        }

        $module = Module::findOrFail($this->pendingUninstallModuleId);

        try {
            $this->moduleLifecycle->uninstall($module, $this->uninstallConfirmation);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0] ?? 'The module could not be uninstalled.');
            }

            return;
        }

        $this->cancelUninstall();
        $this->loadModules();
        $this->dispatch('module-uninstalled');
    }

    /**
     * Reinstall an uninstalled module from its composer-discovered manifest.
     */
    public function reinstallModule(string $moduleId): void
    {
        $module = Module::findOrFail($moduleId);

        if ($module->status !== Module::StatusUninstalled) {
            return;
        }

        try {
            $this->moduleLifecycle->reinstall($module->name);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0] ?? 'The module could not be reinstalled.');
            }

            return;
        }

        $this->loadModules();
        $this->dispatch('module-reinstalled');
    }

    /**
     * Render the module registry management screen.
     */
    public function render(): View
    {
        return view('admin::modules-list');
    }
}
