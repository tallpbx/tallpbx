<?php

declare(strict_types=1);

namespace Modules\Extensions\Services;

use App\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Modules\Extensions\Models\Extension;
use Modules\SipAccounts\Models\SipAccount;
use Modules\SipAccounts\Services\SipAccountServiceInterface;
use Modules\Voicemails\Models\Voicemail;
use Modules\Voicemails\Services\VoicemailServiceInterface;
use Throwable;

/**
 * Saves the complete extension aggregate under one transaction owner.
 *
 * An extension is four related records: the extension row, its portal users,
 * its SIP account, and its voicemail mailbox. Writing them through separate
 * owners left a persisted extension behind whenever a later step threw, so
 * every step now runs inside one `DB::transaction`.
 */
class ExtensionSaveService
{
    /**
     * Create the aggregate service around the module services.
     */
    public function __construct(
        private readonly ExtensionServiceInterface $extensions,
        private readonly SipAccountServiceInterface $sipAccounts,
        private readonly VoicemailServiceInterface $voicemails,
    ) {}

    /**
     * Create or update one extension with its users, SIP account, and voicemail.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $userIds
     *
     * @throws Throwable When any step fails; the whole aggregate is rolled back.
     */
    public function save(
        array $data,
        array $userIds,
        ?string $password,
        bool $voicemailEnabled,
        string $voicemailPassword,
        ?Extension $existing = null,
    ): Extension {
        return DB::transaction(function () use ($data, $userIds, $password, $voicemailEnabled, $voicemailPassword, $existing): Extension {
            $extension = $existing === null
                ? $this->extensions->create($data)
                : $this->extensions->update($existing, $data);

            $this->extensions->syncUsers($extension, $userIds);
            $this->syncSipAccount($extension, $password ?? '', $data);
            $this->syncVoicemail($extension, $voicemailEnabled, $voicemailPassword, $data);

            // Eager-load `users` so Model::preventLazyLoading() never fires
            // when a caller reads the relation after the save.
            return $extension->fresh(['users']);
        });
    }

    /**
     * Mirror the extension into its registration account.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncSipAccount(Extension $extension, string $password, array $data): void
    {
        $sipAccount = SipAccount::withoutGlobalScope('tenant')
            ->where('extension_id', $extension->id)
            ->first();

        // A virtual or routing-only extension deliberately has no registration
        // account, so no account is created without a password.
        if ($sipAccount === null && $password === '') {
            return;
        }

        $tenantId = (int) $extension->tenant_id;
        $tenantDomain = TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->first();
        $userContext = ! empty($data['user_context'])
            ? (string) $data['user_context']
            : "tenant_{$tenantId}_internal";
        $authUsername = (string) $extension->extension_number;

        $payload = [
            'tenant_id' => $tenantId,
            'auth_username' => $authUsername,
            'user_context' => $userContext,
            'enabled' => (bool) ($data['enabled'] ?? $extension->enabled),
        ];

        if ($password !== '') {
            $payload['auth_password'] = $password;
        }

        if ($tenantDomain !== null) {
            $payload['tenant_domain_id'] = $tenantDomain->id;
            $payload['identity_mode'] = 'domain_username';
            $payload['global_auth_key'] = "domain:{$tenantDomain->id}:{$authUsername}";
        } else {
            $payload['global_auth_key'] = "global:{$authUsername}";
        }

        if ($sipAccount !== null) {
            $this->sipAccounts->update($sipAccount, $payload);

            return;
        }

        $this->sipAccounts->create([
            ...$payload,
            'extension_id' => $extension->id,
            'identity_mode' => $tenantDomain !== null ? 'domain_username' : 'global_username',
        ]);
    }

    /**
     * Mirror the extension into its voicemail mailbox.
     *
     * Creating a mailbox provisions a directory, which no transaction can roll
     * back. The orphan directory is owner-scoped, idempotent, and reused by the
     * next save; only orphan rows are prevented.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncVoicemail(Extension $extension, bool $enabled, string $voicemailPassword, array $data): void
    {
        $tenantId = (int) $extension->tenant_id;
        $mailboxId = (string) $extension->extension_number;
        $existing = $this->voicemails->findByMailbox($tenantId, $mailboxId);

        if (! $enabled) {
            if ($existing instanceof Voicemail) {
                $this->voicemails->update($existing, ['enabled' => false]);
            }

            return;
        }

        $password = trim($voicemailPassword);

        if ($password === '') {
            $password = $existing?->password ?: $mailboxId;
        }

        $payload = [
            'tenant_id' => $tenantId,
            'voicemail_id' => $mailboxId,
            'mailbox' => $mailboxId,
            'name' => ! empty($data['display_name'])
                ? $data['display_name'].' Voicemail'
                : "Extension {$mailboxId} Voicemail",
            'password' => $password,
            'enabled' => (bool) ($data['enabled'] ?? $extension->enabled),
        ];

        if ($existing instanceof Voicemail) {
            $this->voicemails->update($existing, $payload);

            return;
        }

        $this->voicemails->create($payload);
    }
}
