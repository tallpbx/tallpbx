<?php

declare(strict_types=1);

namespace Modules\Recordings\Livewire;

use App\Support\BaseEditComponent;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\Recordings\Models\Recording;
use Modules\Recordings\Services\RecordingService;

class RecordingsEdit extends BaseEditComponent
{
    use WithFileUploads;

    public string $name = '';

    public string $type = 'moh';

    public ?string $filePath = null;

    public ?TemporaryUploadedFile $file = null;

    public ?string $recordingId = null;

    private RecordingService $recordingService;

    private MediaStorageServiceInterface $mediaStorage;

    public function boot(RecordingService $recordingService, MediaStorageServiceInterface $mediaStorage): void
    {
        $this->recordingService = $recordingService;
        $this->mediaStorage = $mediaStorage;
    }

    public function mount(?string $recordingId = null): void
    {
        $this->loadTenants();

        if ($recordingId !== null) {
            $this->recordingId = $recordingId;
            $recording = Recording::withoutGlobalScope('tenant')->findOrFail($recordingId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($recording);
            $this->tenantId = $recording->tenant_id;
            $this->name = $recording->name;
            $this->type = $recording->type;
            $this->filePath = $recording->file_path;
            $this->enabled = $recording->enabled;
        }
    }

    public function getIsEditProperty(): bool
    {
        return $this->recordingId !== null;
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $existingRecording = $this->recordingId !== null
            ? Recording::withoutGlobalScope('tenant')->findOrFail($this->recordingId)
            : null;

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'type' => $this->type,
            'file_path' => $existingRecording?->file_path ?? '',
            'enabled' => $this->enabled,
        ];

        if ($existingRecording !== null) {
            $recording = $this->recordingService->update($existingRecording, $data);
        } else {
            $recording = $this->recordingService->create($data);
        }

        if ($this->file !== null) {
            $asset = $this->mediaStorage->storeLocal(
                $recording,
                MediaCategory::Recording,
                $this->file->getRealPath(),
                $this->file->getClientOriginalName(),
                $this->file->getMimeType() ?? 'application/octet-stream',
            );
            $recording->update(['file_path' => $this->mediaStorage->resolveLocalPath($asset->id) ?? '']);
        }

        $this->redirect(route('panel.recordings.index'));
    }

    public function rules(): array
    {
        $rules = [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
            'enabled' => ['boolean'],
        ];

        $rules['file'] = [$this->recordingId === null ? 'required' : 'nullable', 'file', 'mimes:wav,mp3,ogg', 'max:25600'];

        if ($this->recordingId === null) {
            $rules['filePath'] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
