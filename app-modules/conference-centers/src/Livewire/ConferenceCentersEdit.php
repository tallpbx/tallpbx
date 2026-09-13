<?php

declare(strict_types=1);

namespace Modules\ConferenceCenters\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\ConferenceCenters\Models\ConferenceCenter;
use Modules\ConferenceCenters\Services\ConferenceCenterService;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Services\MediaStorageServiceInterface;

/**
 * Livewire component for creating and editing conference centers.
 *
 * Manages form fields for conference center attributes including
 * the dial-in extension, optional PIN, and greeting audio file.
 */
class ConferenceCentersEdit extends BaseEditComponent
{
    use WithFileUploads;

    public string $name = '';

    public string $extension = '';

    public string $pin = '';

    public string $greeting = '';

    public ?TemporaryUploadedFile $greetingUpload = null;

    public ?string $conferenceCenterId = null;

    private ConferenceCenterService $centerService;

    private MediaStorageServiceInterface $mediaStorage;

    /**
     * Inject the conference center service via dependency injection.
     */
    public function boot(ConferenceCenterService $centerService, MediaStorageServiceInterface $mediaStorage): void
    {
        $this->centerService = $centerService;
        $this->mediaStorage = $mediaStorage;
    }

    /**
     * Initialize the component. Loads tenants into the dropdown and,
     * if editing, populates form fields from the existing record.
     */
    public function mount(?string $conferenceCenterId = null): void
    {
        $this->loadTenants();

        if ($conferenceCenterId !== null) {
            $this->conferenceCenterId = $conferenceCenterId;
            $center = ConferenceCenter::withoutGlobalScope('tenant')->findOrFail($conferenceCenterId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($center);
            $this->tenantId = $center->tenant_id;
            $this->name = $center->name;
            $this->extension = $center->extension;
            $this->pin = $center->pin ?? '';
            $this->greeting = $center->greeting ?? '';
            $this->enabled = $center->enabled;
        }
    }

    /**
     * Determine if we are in edit mode (vs. create mode).
     */
    public function getIsEditProperty(): bool
    {
        return $this->conferenceCenterId !== null;
    }

    /**
     * Validate and save the conference center.
     * Creates a new record or updates an existing one inside a transaction.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $existingCenter = $this->conferenceCenterId !== null
            ? ConferenceCenter::withoutGlobalScope('tenant')->findOrFail($this->conferenceCenterId)
            : null;

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'extension' => $this->extension,
            'pin' => $this->pin ?: null,
            'greeting' => $existingCenter?->greeting,
            'enabled' => $this->enabled,
        ];

        if ($existingCenter !== null) {
            $center = $this->centerService->update($existingCenter, $data);
        } else {
            $center = $this->centerService->create($data);
        }

        if ($this->greetingUpload !== null) {
            $asset = $this->mediaStorage->storeLocal(
                $center,
                MediaCategory::ConferenceGreeting,
                $this->greetingUpload->getRealPath(),
                $this->greetingUpload->getClientOriginalName(),
                $this->greetingUpload->getMimeType() ?? 'application/octet-stream',
            );
            $center->update(['greeting' => $this->mediaStorage->resolveLocalPath($asset->id)]);
        }

        $this->redirect(route('panel.conference-centers.index'));

        // Queue a reloadxml so FreeSWITCH picks up the conference center change
        ReloadFreeSwitchXml::dispatch('conference center saved');
    }

    /**
     * Validation rules for the conference center form.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'extension' => ['required', 'string', 'max:255'],
            'pin' => ['nullable', 'string', 'max:20'],
            'greetingUpload' => ['nullable', 'file', 'mimes:wav,mp3,ogg', 'max:25600'],
            'enabled' => ['boolean'],
        ];
    }
}
