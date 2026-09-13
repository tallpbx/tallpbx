<?php

declare(strict_types=1);

namespace Modules\IvrMenus\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\IvrMenus\Livewire\Validation\IvrMenuValidation;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\IvrMenus\Services\IvrMenuServiceInterface;

/**
 * Livewire component for creating and editing IVR menus.
 * Handles form validation, data persistence, and redirects
 * back to the IVR menu list on success.
 */
class IvrMenusEdit extends BaseEditComponent
{
    use WithFileUploads;

    public string $name = '';

    public string $greeting = '';

    public ?TemporaryUploadedFile $greetingUpload = null;

    public string $description = '';

    public int $timeout = 10;

    public int $maxFailures = 3;

    public int $digitLength = 0;

    public ?string $menuUuid = null;

    private IvrMenuServiceInterface $ivrMenuService;

    private MediaStorageServiceInterface $mediaStorage;

    /**
     * Inject the IVR menu service via dependency injection.
     */
    public function boot(IvrMenuServiceInterface $ivrMenuService, MediaStorageServiceInterface $mediaStorage): void
    {
        $this->ivrMenuService = $ivrMenuService;
        $this->mediaStorage = $mediaStorage;
    }

    /**
     * Initialize the component. Loads tenants into the dropdown.
     * If a menuUuid is provided, loads existing IVR menu data for editing.
     */
    public function mount(?string $menuUuid = null): void
    {
        $this->loadTenants();

        if ($menuUuid !== null) {
            $this->menuUuid = $menuUuid;
            $menu = IvrMenu::withoutGlobalScope('tenant')->findOrFail($menuUuid);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($menu);
            $this->tenantId = $menu->tenant_id;
            $this->name = $menu->name;
            $this->greeting = $menu->greeting ?? '';
            $this->description = $menu->description ?? '';
            $this->timeout = $menu->timeout;
            $this->maxFailures = $menu->max_failures;
            $this->digitLength = $menu->digit_length;
            $this->enabled = $menu->enabled;
        }
    }

    /**
     * Determine if we are in edit mode (vs. create mode).
     */
    public function getIsEditProperty(): bool
    {
        return $this->menuUuid !== null;
    }

    /**
     * Validate and save the IVR menu. Creates a new record or
     * updates an existing one, then redirects to the list page.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $existingMenu = $this->menuUuid !== null
            ? IvrMenu::withoutGlobalScope('tenant')->findOrFail($this->menuUuid)
            : null;

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'greeting' => $existingMenu?->greeting,
            'description' => $this->description ?: null,
            'timeout' => $this->timeout,
            'max_failures' => $this->maxFailures,
            'digit_length' => $this->digitLength,
            'enabled' => $this->enabled,
        ];

        if ($existingMenu !== null) {
            $menu = $this->ivrMenuService->update($existingMenu, $data);
        } else {
            $menu = $this->ivrMenuService->create($data);
        }

        if ($this->greetingUpload !== null) {
            $asset = $this->mediaStorage->storeLocal(
                $menu,
                MediaCategory::IvrGreeting,
                $this->greetingUpload->getRealPath(),
                $this->greetingUpload->getClientOriginalName(),
                $this->greetingUpload->getMimeType() ?? 'application/octet-stream',
            );
            $menu->update(['greeting' => $this->mediaStorage->resolveLocalPath($asset->id)]);
        }

        $this->redirect(route('panel.ivr-menus.index'));

        // Queue a reloadxml so FreeSWITCH picks up the IVR menu change
        ReloadFreeSwitchXml::dispatch('IVR menu saved');
    }

    /**
     * Validation rules for the IVR menu form.
     */
    public function rules(): array
    {
        return array_merge(IvrMenuValidation::rules(
            menuUuid: $this->menuUuid,
            tenantId: $this->tenantId,
        ), [
            'greetingUpload' => ['nullable', 'file', 'mimes:wav,mp3,ogg', 'max:25600'],
        ]);
    }
}
