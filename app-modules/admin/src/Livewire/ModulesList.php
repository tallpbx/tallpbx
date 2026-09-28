<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Module;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Livewire component for managing installed modules.
 *
 * Admin-only access. The page offers only the enable/disable toggle;
 * complete uninstall and restore run through the module:uninstall and
 * module:restore CLI commands. Required and protected modules cannot be
 * disabled, and uninstalled rows show a CLI restore hint.
 */
#[Layout('layouts.app')]
class ModulesList extends Component
{
    /** @var Collection<int, Module> */
    public Collection $modules;

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
     * Required and protected modules cannot be disabled.
     */
    public function toggleEnabled(string $moduleId): void
    {
        $module = Module::findOrFail($moduleId);

        if ($module->status === Module::StatusUninstalled) {
            return;
        }

        // Required and protected modules cannot be disabled
        if (($module->required || $module->protected) && $module->enabled) {
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
     * Render the module registry management screen.
     */
    public function render(): View
    {
        return view('admin::modules-list');
    }
}
