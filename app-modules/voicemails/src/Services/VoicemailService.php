<?php

declare(strict_types=1);

namespace Modules\Voicemails\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\Voicemails\Models\Voicemail;

/**
 * Service implementation for managing voicemail mailboxes with
 * tenant-scoped uniqueness validation and FreeSWITCH dialplan
 * XML generation.
 */
class VoicemailService implements ContextWideDialplanXmlContributor, VoicemailServiceInterface
{
    /** Create the voicemail service with managed-media path resolution. */
    public function __construct(private readonly MediaStorageServiceInterface $mediaStorage) {}

    /**
     * Feature-level routing — voicemail matches after emergency/blocks/DID matching.
     */
    public function getDialplanPriority(): int
    {
        return 70;
    }

    /**
     * Create a new voicemail mailbox.
     */
    public function create(array $data): Voicemail
    {
        $this->validateUniqueVoicemailId($data['tenant_id'], $data['voicemail_id']);

        $voicemail = Voicemail::create($data);

        $this->provisionMailboxDirectory((int) $voicemail->tenant_id, $voicemail->mailbox);

        return $voicemail;
    }

    /**
     * Pre-create the FreeSWITCH deposit directory for a mailbox.
     *
     * mod_voicemail creates this subdirectory itself with mode 0750, which
     * the web application cannot write into when it archives the message
     * out; creating it here with the shared media group keeps both the
     * FreeSWITCH deposit and the app archive move working.
     */
    private function provisionMailboxDirectory(int $tenantId, string $mailbox): void
    {
        $directory = rtrim((string) config('media-storage.store_root'), '/')
            .'/runtime/'.$tenantId.'/voicemail-message/'.$mailbox;

        File::ensureDirectoryExists($directory);

        // chmod is used (not mkdir mode) so the umask cannot strip the
        // group-write bit; the setgid bit keeps new files in the media group.
        chmod($directory, 02775);
    }

    /**
     * Update an existing voicemail mailbox.
     */
    public function update(Voicemail $voicemail, array $data): Voicemail
    {
        if (isset($data['voicemail_id']) && $data['voicemail_id'] !== $voicemail->voicemail_id) {
            $tenantId = $data['tenant_id'] ?? $voicemail->tenant_id;
            $this->validateUniqueVoicemailId($tenantId, $data['voicemail_id'], $voicemail->id);
        }

        $voicemail->update($data);

        return $voicemail->fresh();
    }

    /**
     * Delete a voicemail mailbox.
     */
    public function delete(Voicemail $voicemail): void
    {
        if ($voicemail->mediaAsset !== null) {
            $this->mediaStorage->requestDeletion($voicemail->mediaAsset->id);
        }

        $voicemail->delete();
    }

    /**
     * Get all voicemail mailboxes for a tenant.
     */
    public function getByTenant(int $tenantId): Collection
    {
        return Voicemail::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->orderBy('voicemail_id')->get();
    }

    /**
     * Find a voicemail mailbox by its mailbox number.
     */
    public function findByMailbox(int $tenantId, string $mailbox): ?Voicemail
    {
        return Voicemail::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
            ->where('mailbox', $mailbox)
            ->first();
    }

    /**
     * Ensure the voicemail_id is unique within the tenant.
     *
     * @throws ValidationException
     */
    private function validateUniqueVoicemailId(int $tenantId, string $voicemailId, ?string $excludeId = null): void
    {
        $query = Voicemail::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
            ->where('voicemail_id', $voicemailId);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'voicemail_id' => ['The voicemail ID is already taken within this tenant.'],
            ]);
        }
    }

    /**
     * Generate dialplan XML for voicemail mailboxes.
     *
     * Each enabled voicemail mailbox contributes an extension that
     * routes to FreeSWITCH's voicemail application with the mailbox
     * number and optional greeting/domain parameters.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        $voicemails = Voicemail::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->with('mediaAsset')
            ->orderBy('voicemail_id')
            ->get();

        if ($voicemails->isEmpty()) {
            return null;
        }

        $xml = '';

        foreach ($voicemails as $vm) {
            $safeId = htmlspecialchars($vm->voicemail_id, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeMailbox = htmlspecialchars($vm->mailbox, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $xml .= "      <extension name=\"voicemail_{$safeId}\">\n";
            $xml .= "        <condition field=\"destination_number\" expression=\"^{$safeMailbox}$\">\n";

            // Answer the call before sending to voicemail
            $xml .= "          <action application=\"answer\"/>\n";
            $xml .= "          <action application=\"sleep\" data=\"1000\"/>\n";

            $greeting = $this->resolveGreetingPath($vm->mediaAsset?->id, $vm->greeting_message);

            if ($greeting !== null) {
                $safeGreeting = htmlspecialchars($greeting, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "          <action application=\"playback\" data=\"{$safeGreeting}\"/>\n";
            }

            // Route to the voicemail application
            $xml .= "          <action application=\"voicemail\" data=\"default \${domain} {$safeMailbox}\"/>\n";

            $xml .= "        </condition>\n";
            $xml .= "      </extension>\n";
        }

        return $xml;
    }

    /** Resolve a managed greeting first while preserving legacy paths during migration. */
    private function resolveGreetingPath(?string $mediaAssetId, ?string $legacyPath): ?string
    {
        if ($mediaAssetId !== null) {
            try {
                return $this->mediaStorage->resolveLocalPath($mediaAssetId);
            } catch (\RuntimeException) {
                return null;
            }
        }

        return $legacyPath !== '' ? $legacyPath : null;
    }
}
