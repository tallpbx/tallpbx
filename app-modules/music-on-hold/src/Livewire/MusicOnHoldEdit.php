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

    public function boot(MusicOnHoldService $musicOnHoldService, MediaStorageServiceInterface $mediaStorage): void
    {
        $this->musicOnHoldService = $musicOnHoldService;
        $this->mediaStorage = $mediaStorage;
    }

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

    public function getIsEditProperty(): bool
    {
        return $this->mohId !== null;
    }

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
