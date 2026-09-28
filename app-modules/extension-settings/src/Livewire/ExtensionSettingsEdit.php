<?php

declare(strict_types=1);

namespace Modules\ExtensionSettings\Livewire;

use App\Support\BaseEditComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Extensions\Models\Extension;
use Modules\ExtensionSettings\Models\ExtensionSetting;
use Modules\ExtensionSettings\Services\ExtensionSettingServiceInterface;

/**
 * Livewire component for creating and editing per-extension settings.
 */
class ExtensionSettingsEdit extends BaseEditComponent
{
    /** @var Collection<int, Extension> */
    public Collection $extensions;

    public ?string $extensionId = null;

    public string $key = '';

    public string $value = '';

    public ?string $settingId = null;

    private ExtensionSettingServiceInterface $service;

    /**
     * Inject the extension setting service used by this component.
     */
    public function boot(ExtensionSettingServiceInterface $service): void
    {
        $this->service = $service;
    }

    /**
     * Open the create form, or load the given setting for editing when
     * a record id is supplied (tenant users may only open their own).
     */
    public function mount(?string $settingId = null): void
    {
        $this->loadTenants();
        $this->extensions = new Collection;

        if ($settingId === null) {
            return;
        }

        $setting = ExtensionSetting::withoutGlobalScope('tenant')->findOrFail($settingId);
        // Tenant users may only open records of their active tenant.
        $this->assertCanAccessTenantRecord($setting);
        $this->settingId = $setting->id;
        $this->tenantId = $setting->tenant_id;
        $this->extensionId = $setting->extension_id;
        $this->key = $setting->key;
        $this->value = $setting->value ?? '';
    }

    /**
     * Reload the extension choices whenever the selected tenant changes.
     */
    public function updatedTenantId($value): void
    {
        if ($value) {
            $this->extensions = Extension::withoutGlobalScope('tenant')
                ->where('tenant_id', $value)
                ->orderBy('extension_number')
                ->get();
        } else {
            $this->extensions = new Collection;
        }
    }

    /**
     * Whether the form is editing an existing setting rather than
     * creating a new one.
     */
    public function getIsEditProperty(): bool
    {
        return $this->settingId !== null;
    }

    /**
     * Validate the form and create or update the extension setting.
     */
    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenantId,
            'extension_id' => $this->extensionId,
            'key' => $this->key,
            'value' => $this->value,
        ];

        if ($this->isEdit) {
            $setting = ExtensionSetting::withoutGlobalScope('tenant')->findOrFail($this->settingId);
            $this->service->update($setting, $data);
        } else {
            $this->service->create($data);
        }

        $this->redirect(route('panel.extension-settings.index'), navigate: true);
    }

    /**
     * Validation rules for the extension setting form.
     */
    public function rules(): array
    {
        return [
            'tenantId' => 'required|exists:tenants,id',
            'extensionId' => 'required|exists:extensions,id',
            'key' => 'required|string|max:255',
            'value' => 'nullable|string|max:255',
        ];
    }
}
