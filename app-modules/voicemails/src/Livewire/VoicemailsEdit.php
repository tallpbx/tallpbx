<?php

declare(strict_types=1);

namespace Modules\Voicemails\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\Voicemails\Livewire\Validation\VoicemailValidation;
use Modules\Voicemails\Models\Voicemail;
use Modules\Voicemails\Services\VoicemailServiceInterface;

/**
 * Livewire component for creating and editing voicemail mailboxes.
 */
class VoicemailsEdit extends BaseEditComponent
{
    use WithFileUploads;

    public string $voicemailId = '';

    public string $mailbox = '';

    public string $name = '';

    public string $password = '';

    public string $email = '';

    public string $greetingMessage = '';

    public ?TemporaryUploadedFile $greetingUpload = null;

    public bool $requirePassword = true;

    public bool $forwardToEmail = false;

    public bool $deleteAfterEmail = false;

    public ?string $voicemailUuid = null;

    private VoicemailServiceInterface $voicemailService;

    private MediaStorageServiceInterface $mediaStorage;

    /**
     * Boot the component with the voicemail service.
     */
    public function boot(VoicemailServiceInterface $voicemailService, MediaStorageServiceInterface $mediaStorage): void
    {
        $this->voicemailService = $voicemailService;
        $this->mediaStorage = $mediaStorage;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?string $voicemailUuid = null): void
    {
        $this->loadTenants();

        if ($voicemailUuid !== null) {
            $this->voicemailUuid = $voicemailUuid;
            $voicemail = Voicemail::withoutGlobalScope('tenant')->findOrFail($voicemailUuid);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($voicemail);
            $this->tenantId = $voicemail->tenant_id;
            $this->voicemailId = $voicemail->voicemail_id;
            $this->mailbox = $voicemail->mailbox;
            $this->name = $voicemail->name ?? '';
            $this->password = $voicemail->password ?? '';
            $this->email = $voicemail->email ?? '';
            $this->greetingMessage = $voicemail->greeting_message ?? '';
            $this->requirePassword = $voicemail->require_password;
            $this->forwardToEmail = $voicemail->forward_to_email;
            $this->deleteAfterEmail = $voicemail->delete_after_email;
            $this->enabled = $voicemail->enabled;
        } else {
            if (! $this->isAdminGuard()) {
                $this->tenantId = $this->resolveTenantId();
            }
        }
    }

    public function getIsEditProperty(): bool
    {
        return $this->voicemailUuid !== null;
    }

    public function save(): void
    {
        $this->tenantId = $this->resolveTenantId() ?? $this->tenantId;
        $this->validate($this->rules());

        $existingVoicemail = $this->voicemailUuid !== null
            ? Voicemail::withoutGlobalScope('tenant')->findOrFail($this->voicemailUuid)
            : null;

        $data = [
            'tenant_id' => $this->tenantId,
            'voicemail_id' => $this->voicemailId,
            'mailbox' => $this->mailbox ?: $this->voicemailId,
            'name' => $this->name ?: null,
            'password' => $this->password ?: null,
            'email' => $this->email ?: null,
            'greeting_message' => $existingVoicemail?->greeting_message,
            'require_password' => $this->requirePassword,
            'forward_to_email' => $this->forwardToEmail,
            'delete_after_email' => $this->deleteAfterEmail,
            'enabled' => $this->enabled,
        ];

        if ($existingVoicemail !== null) {
            $this->assertCanAccessTenantRecord($existingVoicemail);
            $voicemail = $this->voicemailService->update($existingVoicemail, $data);
        } else {
            $voicemail = $this->voicemailService->create($data);
        }

        if ($this->greetingUpload !== null) {
            $asset = $this->mediaStorage->storeLocal(
                $voicemail,
                MediaCategory::VoicemailGreeting,
                $this->greetingUpload->getRealPath(),
                $this->greetingUpload->getClientOriginalName(),
                $this->greetingUpload->getMimeType() ?? 'application/octet-stream',
            );
            $voicemail->update(['greeting_message' => $this->mediaStorage->resolveLocalPath($asset->id)]);
        }

        $this->redirect(route('panel.voicemails.index'));

        // Queue a reloadxml so FreeSWITCH picks up the voicemail change
        ReloadFreeSwitchXml::dispatch('voicemail saved');
    }

    protected function rules(): array
    {
        return array_merge(VoicemailValidation::rules(
            voicemailUuid: $this->voicemailUuid,
            tenantId: $this->tenantId,
        ), [
            'greetingUpload' => ['nullable', 'file', 'mimes:wav,mp3,ogg', 'max:25600'],
        ]);
    }
}
