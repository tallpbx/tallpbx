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

/**
 * Livewire component for creating and editing recordings.
 */
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

    /**
     * Inject the recording service and the media storage service used
     * for uploaded audio files.
     */
    public function boot(RecordingService $recordingService, MediaStorageServiceInterface $mediaStorage): void
    {
        $this->recordingService = $recordingService;
        $this->mediaStorage = $mediaStorage;
    }

    /**
     * Open the create form, or load the given recording for editing when
     * a record id is supplied (tenant users may only open their own).
     */
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

    /**
     * Whether the form is editing an existing recording rather than
     * creating a new one.
     */
    public function getIsEditProperty(): bool
    {
        return $this->recordingId !== null;
    }

    /**
     * Validate the form, store the recording, and store any uploaded
     * file as a managed media asset.
     */
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

    /**
     * Validation rules for the recording form.
     */
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
