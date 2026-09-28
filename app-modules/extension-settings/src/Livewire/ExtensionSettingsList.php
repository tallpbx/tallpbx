<?php

declare(strict_types=1);

namespace Modules\ExtensionSettings\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\ExtensionSettings\Models\ExtensionSetting;
use Modules\ExtensionSettings\Services\ExtensionSettingServiceInterface;

/**
 * Livewire component listing per-extension settings with a delete action.
 */
class ExtensionSettingsList extends BaseListComponent
{
    /** @var Collection<int, ExtensionSetting> */
    public Collection $settings;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private ExtensionSettingServiceInterface $service;

    /**
     * Inject the extension setting service used by this component.
     */
    public function boot(ExtensionSettingServiceInterface $service): void
    {
        $this->service = $service;
    }

    /**
     * Load the settings list when the page opens.
     */
    public function mount(): void
    {
        $this->load();
    }

    /**
     * Fetch settings with their extensions ordered by key.
     * Administrators see every tenant's settings; tenant users only
     * see their own.
     */
    private function load(): void
    {
        $query = $this->isAdminGuard()
            ? ExtensionSetting::withoutGlobalScope('tenant')
            : ExtensionSetting::query();

        $this->settings = $query->with('extension')->orderBy('key')->get();
    }

    /**
     * Delete the confirmed setting and refresh the list.
     */
    public function deleteSetting(string $id): void
    {
        $setting = ExtensionSetting::withoutGlobalScope('tenant')->findOrFail($id);
        $this->service->delete($setting);
        $this->cancelSettingDeletion();
        $this->load();
        $this->showSuccess('Extension setting deleted.');
        $this->dispatch('notify', message: __('admin.deleted'));
    }

    /** Open the shared deletion confirmation for an extension setting. */
    public function confirmSettingDeletion(string $id): void
    {
        $setting = ExtensionSetting::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $setting->id;
        $this->pendingDeletionName = $setting->key;
    }

    /** Close the extension-setting deletion confirmation. */
    public function cancelSettingDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
