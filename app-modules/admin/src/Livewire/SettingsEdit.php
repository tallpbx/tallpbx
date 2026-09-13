<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Setting;
use App\Services\SettingServiceInterface;
use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Livewire component for managing system settings.
 *
 * Admin-only access. Displays all system settings
 * with inline editing and the ability to add new
 * key-value pairs or delete existing ones.
 */
#[Layout('layouts.app')]
class SettingsEdit extends Component
{
    use HasOperationalFeedback;

    /** @var Collection<int, Setting> */
    public Collection $settings;

    public ?int $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    public ?string $deleteError = null;

    // New setting form
    public string $newKey = '';

    public string $newValue = '';

    public string $newType = 'string';

    // Edit form
    public ?int $editingId = null;

    public string $editKey = '';

    public string $editValue = '';

    public string $editType = 'string';

    private SettingServiceInterface $settingService;

    public function boot(SettingServiceInterface $settingService): void
    {
        $this->settingService = $settingService;
    }

    public function mount(): void
    {
        $this->loadSettings();
    }

    /**
     * Load all system settings.
     */
    private function loadSettings(): void
    {
        $this->settings = $this->settingService->all();
    }

    /**
     * Create a new setting.
     */
    public function createSetting(): void
    {
        $validated = $this->validate([
            'newKey' => ['required', 'string', 'max:255', Rule::unique('settings', 'key')],
            'newValue' => ['required', 'string'],
            'newType' => ['required', 'string', 'in:string,integer,boolean,json'],
        ]);

        $this->settingService->set(
            $validated['newKey'],
            $validated['newValue'],
            $validated['newType'],
        );

        $this->reset(['newKey', 'newValue', 'newType']);
        $this->newType = 'string';
        $this->loadSettings();
        $this->dispatch('setting-created');
    }

    /**
     * Begin editing a setting.
     */
    public function editSetting(int $settingId): void
    {
        $setting = Setting::findOrFail($settingId);

        $this->editingId = $setting->id;
        $this->editKey = $setting->key;
        $this->editValue = $setting->value;
        $this->editType = $setting->type;
    }

    /**
     * Save changes to the currently edited setting.
     */
    public function saveSetting(): void
    {
        $this->validate([
            'editValue' => ['required', 'string'],
        ]);

        $setting = Setting::findOrFail($this->editingId);

        $this->settingService->set(
            $setting->key,
            $this->editValue,
            $this->editType,
        );

        $this->cancelEdit();
        $this->loadSettings();
        $this->dispatch('setting-saved');
    }

    /**
     * Cancel the current edit.
     */
    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editKey', 'editValue', 'editType']);
        $this->editType = 'string';
    }

    /** Open the shared confirmation modal for one setting. */
    public function confirmSettingDeletion(int $settingId): void
    {
        $setting = Setting::findOrFail($settingId);
        $this->pendingDeletionId = $setting->id;
        $this->pendingDeletionName = $setting->key;
        $this->deleteError = null;
    }

    /** Close the setting deletion confirmation without deleting. */
    public function cancelSettingDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName', 'deleteError');
    }

    /** Delete the setting that the user confirmed, keeping expected failures in the modal. */
    public function deleteSetting(): void
    {
        if ($this->pendingDeletionId === null) {
            return;
        }

        $setting = Setting::findOrFail($this->pendingDeletionId);

        try {
            $this->settingService->delete($setting->key);
        } catch (RuntimeException $exception) {
            $this->deleteError = 'Setting could not be deleted. '.$exception->getMessage();

            return;
        }

        $this->cancelSettingDeletion();
        $this->loadSettings();
        $this->showSuccess('Setting deleted.');
        $this->dispatch('setting-deleted');
    }

    public function render(): View
    {
        return view('admin::settings-edit');
    }
}
