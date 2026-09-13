<?php

declare(strict_types=1);

namespace App\Listeners\FreeSwitch;

use App\Events\FreeSwitch\ChannelExecuteComplete;
use App\Models\Tenant;
use App\Services\DialplanContext;
use Illuminate\Support\Facades\Log;
use Modules\VoicemailMessages\Services\VoicemailMessageService;
use Modules\Voicemails\Models\Voicemail;

/** Registers completed FreeSWITCH voicemail files as local-only media. */
class RegisterCompletedVoicemailMessage
{
    /**
     * Create the listener with trusted tenant-context resolution.
     */
    public function __construct(
        private readonly VoicemailMessageService $messages,
        private readonly DialplanContext $dialplanContext,
    ) {}

    /** Register only non-empty files from the configured managed voicemail root. */
    public function handle(ChannelExecuteComplete $event): void
    {
        if ($event->header('Application') !== 'voicemail') {
            return;
        }
        $tenantId = $this->resolveTenantId($event);
        $path = $event->header('variable_voicemail_file_path');

        // mod_voicemail's deposit path sets voicemail_account and
        // voicemail_domain channel variables (never voicemail_id); keep the
        // legacy names as a fallback for other event producers.
        $mailbox = $event->header('variable_voicemail_account') ?? $event->header('variable_voicemail_id');
        $domain = $event->header('variable_voicemail_domain') ?? $event->header('variable_domain_name');
        $uuid = $path === null ? null : pathinfo($path, PATHINFO_FILENAME);

        // mod_voicemail saves messages as msg_<uuid>.wav; strip the prefix so
        // the uuid matches the fsdb id used by the delete and purge APIs.
        if ($uuid !== null && str_starts_with($uuid, 'msg_')) {
            $uuid = substr($uuid, 4);
        }

        $root = $tenantId === null ? false : realpath(rtrim((string) config('media-storage.store_root'), DIRECTORY_SEPARATOR)."/runtime/{$tenantId}/voicemail-message");
        $resolved = $path === null ? false : realpath($path);
        if ($tenantId === null || ! Tenant::query()->whereKey($tenantId)->exists() || $mailbox === null || $domain === null || $root === false || $resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || ! is_file($resolved) || filesize($resolved) <= 0 || $uuid === null || ! preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27}$/i', $uuid)) {
            Log::warning('Ignored invalid completed voicemail event.', [
                'tenant_id' => $tenantId,
                'has_path' => $path !== null,
                'mailbox' => $mailbox,
                'domain' => $domain,
            ]);

            return;
        }
        $voicemail = Voicemail::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('mailbox', $mailbox)->first();
        if ($voicemail === null) {
            Log::warning('Ignored voicemail event for unknown mailbox.', ['tenant_id' => $tenantId]);

            return;
        }
        $this->messages->registerCompleted(['tenant_id' => $tenantId, 'voicemail_id' => $voicemail->id, 'file_path' => $resolved, 'caller_id' => $event->header('Caller-Caller-ID-Number'), 'caller_id_name' => $event->header('Caller-Caller-ID-Name'), 'duration' => max(0, (int) $event->header('variable_voicemail_message_len', '0')), 'message_uuid' => $uuid, 'domain' => $domain, 'folder' => $event->header('variable_voicemail_folder', 'inbox')]);
    }

    /**
     * Resolve an explicit tenant or an unambiguous FreeSWITCH tenant context.
     */
    private function resolveTenantId(ChannelExecuteComplete $event): ?int
    {
        $explicit = $event->header('variable_tenant_id');
        $explicitId = ctype_digit((string) $explicit) ? (int) $explicit : null;
        $contextId = $this->dialplanContext->parseTenantId((string) $event->header('variable_user_context', ''));

        if ($explicitId !== null && $contextId !== null && (string) $explicitId !== $contextId) {
            return null;
        }

        return $explicitId ?? (ctype_digit((string) $contextId) ? (int) $contextId : null);
    }
}
