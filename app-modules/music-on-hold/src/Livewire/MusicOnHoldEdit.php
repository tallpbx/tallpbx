<?php

declare(strict_types=1);

namespace Modules\MusicOnHold\Livewire;

use App\Support\BaseEditComponent;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\MusicOnHold\Models\MusicOnHold;
use Modules\MusicOnHold\Services\MusicOnHoldService;

/**
 * Livewire component for creating and editing music on hold entries.
 */
class MusicOnHoldEdit extends BaseEditComponent
{
    use WithFileUploads;

    public string $name = '';

    public ?string $audioFile = null;

    public ?TemporaryUploadedFile $audioUpload = null;

    public string $description = '';

    public ?string $mohId = null;

    private MusicOnHoldService $musicOnHoldService;

    private MediaStorageServiceInterface $mediaStorage;

    /**
     * Inject the music on hold service and the media storage service
     * used for audio file cleanup.
     */
    public function boot(MusicOnHoldService $musicOnHoldService, MediaStorageServiceInterface $mediaStorage): void
    {
        $this->musicOnHoldService = $musicOnHoldService;
        $this->mediaStorage = $mediaStorage;
    }

    /**
     * Open the create form, or load the given entry for editing when a
     * record id is supplied (tenant users may only open their own).
     */
    public function mount(?string $mohId = null): void
    {
        $this->loadTenants();

        if ($mohId !== null) {
            $this->mohId = $mohId;
            $moh = MusicOnHold::withoutGlobalScope('tenant')->findOrFail($mohId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($moh);
            $this->tenantId = $moh->tenant_id;
            $this->name = $moh->name;
            $this->audioFile = $moh->audio_file;
            $this->description = $moh->description ?? '';
            $this->enabled = $moh->enabled;
        }
    }

    /**
     * Whether the form is editing an existing entry rather than
     * creating a new one.
     */
    public function getIsEditProperty(): bool
    {
        return $this->mohId !== null;
    }

    /**
     * Validate the form, store the entry, and store any uploaded audio
     * as a managed media asset.
     */
    public function save(): void
    {
        $this->validate();

        $existingMusicOnHold = $this->mohId !== null
            ? MusicOnHold::withoutGlobalScope('tenant')->findOrFail($this->mohId)
            : null;

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'audio_file' => $existingMusicOnHold?->audio_file,
            'description' => $this->description ?: null,
            'enabled' => $this->enabled,
        ];

        if ($existingMusicOnHold !== null) {
            $moh = $this->musicOnHoldService->update($existingMusicOnHold, $data);
        } else {
            $moh = $this->musicOnHoldService->create($data);
        }

        if ($this->audioUpload !== null) {
            $asset = $this->mediaStorage->storeLocal(
                $moh,
                MediaCategory::MusicOnHold,
                $this->audioUpload->getRealPath(),
                $this->audioUpload->getClientOriginalName(),
                $this->audioUpload->getMimeType() ?? 'application/octet-stream',
            );
            $moh->update(['audio_file' => $this->mediaStorage->resolveLocalPath($asset->id)]);
        }

        $this->redirect(route('panel.music-on-hold.index'));
    }

    /**
     * Validation rules for the music on hold form.
     */
    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'audioUpload' => ['nullable', 'file', 'mimes:wav,mp3,ogg', 'max:25600'],
            'description' => ['nullable', 'string', 'max:65535'],
        ];
    }
}
