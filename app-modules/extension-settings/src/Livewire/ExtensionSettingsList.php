<?php

declare(strict_types=1);

namespace Modules\ExtensionSettings\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\ExtensionSettings\Models\ExtensionSetting;
use Modules\ExtensionSettings\Services\ExtensionSettingServiceInterface;

class ExtensionSettingsList extends BaseListComponent
{
    /** @var Collection<int, ExtensionSetting> */
    public Collection $settings;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private ExtensionSettingServiceInterface $service;

    public function boot(ExtensionSettingServiceInterface $service): void
    {
        $this->service = $service;
    }

    public function mount(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $this->settings = ExtensionSetting::withoutGlobalScope('tenant')
            ->with('extension')
            ->orderBy('key')
            ->get();
    }

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
