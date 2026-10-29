<?php

declare(strict_types=1);

namespace App\Services\Xml;

use App\Services\TenantManager;
use App\Support\Concerns\EscapesXml;
use App\Support\RoutingCacheVersion;
use App\Support\Xml\XmlWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Extensions\Models\Extension;
use Modules\ExtensionSettings\Models\ExtensionSetting;
use Modules\SipAccounts\Models\SipAccount;

/**
 * Builds the FreeSWITCH directory-section XML, with per-tenant caching.
 */
class DirectoryXmlBuilder
{
    use EscapesXml;

    /**
     * Extension setting keys that are safe to export as directory variables.
     *
     * Settings are admin-entered strings; this allowlist is the XML-safety
     * boundary — anything not listed here never reaches FreeSWITCH.
     *
     * @var list<string>
     */
    private const ALLOWED_EXTENSION_SETTINGS = [
        'user_context',
        'effective_caller_id_name',
        'effective_caller_id_number',
        'call_waiting',
        'hold_music',
        'transfer_fallback_extension',
        'accountcode',
    ];

    /**
     * Create the builder with the tenant context source used by cache keys.
     */
    public function __construct(private readonly TenantManager $tenantManager) {}

    /**
     * Build directory XML for a user or domain lookup.
     *
     * Returns a minimal directory structure. This will be extended
     * as SIP account and domain modules are implemented in later phases.
     */
    private function buildDirectoryXml(string $tagName, string $domain, string $username, string $keyValue): string
    {
        $writer = new XmlWriter;
        $writer->startDocument();
        $writer->openTag('document', ['type' => 'freeswitch/xml']);

        $tenantId = $this->tenantManager->getTenantId();

        if ($tagName === 'domain') {
            $writer->openTag('section', ['name' => 'directory'], 2);
            $writer->openTag('domain', ['name' => $domain], 4);
            $writer->openTag('params', [], 6);
            $writer->selfClosingTag('param', ['name' => 'dial-string', 'value' => '{presence_id=\${dialed_user}@\${dialed_domain}}\${sofia_contact(\${dialed_user}@\${dialed_domain})}'], 8);
            $writer->closeTag('params', 6);
            $writer->openTag('groups', [], 6);
            $writer->openTag('group', ['name' => 'default'], 8);
            $writer->openTag('users', [], 10);

            if ($tenantId !== null) {
                /** @var Collection<int, SipAccount> $accounts */
                $accounts = SipAccount::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('enabled', true)
                    ->get();

                $extensionIds = $accounts->pluck('extension_id')->filter()->unique()->values();

                $extensions = $extensionIds->isNotEmpty()
                    ? Extension::withoutGlobalScope('tenant')->whereIn('id', $extensionIds)->get()->keyBy('id')
                    : collect();

                $settings = $extensionIds->isNotEmpty()
                    ? ExtensionSetting::withoutGlobalScope('tenant')
                        ->where('tenant_id', $tenantId)
                        ->whereIn('extension_id', $extensionIds)
                        ->whereIn('key', self::ALLOWED_EXTENSION_SETTINGS)
                        ->get()
                        ->groupBy('extension_id')
                    : collect();

                foreach ($accounts as $account) {
                    $writer->rawTrustedFragment($this->buildUserEntryXml($account, $extensions, $settings));
                }
            }

            $writer->closeTag('users', 10);
            $writer->closeTag('group', 8);
            $writer->closeTag('groups', 6);
            $writer->closeTag('domain', 4);
            $writer->closeTag('section', 2);
        } elseif ($tagName === 'user') {
            $lookupUsername = $username !== '' ? $username : $keyValue;
            $found = false;

            if ($tenantId !== null && $lookupUsername !== '') {
                /** @var SipAccount|null $account */
                $account = SipAccount::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('auth_username', $lookupUsername)
                    ->where('enabled', true)
                    ->first();

                if ($account !== null) {
                    $found = true;
                    $writer->openTag('section', ['name' => 'directory'], 2);
                    $writer->rawTrustedFragment($this->buildUserEntryXml($account));
                    $writer->closeTag('section', 2);
                }
            }

            if (! $found) {
                $writer->openTag('section', ['name' => 'directory'], 2);
                $writer->comment('User not found: '.$writer->escape($lookupUsername), 4);
                $writer->closeTag('section', 2);
            }
        } else {
            $writer->openTag('section', ['name' => 'directory'], 2);
            $writer->selfClosingTag($tagName, ['name' => $keyValue], 4);
            $writer->closeTag('section', 2);
        }

        $writer->closeTag('document', 0, false);

        return $writer->toString();
    }

    /**
     * Build directory XML, using short-lived cache for repeated SIP lookups.
     */
    public function buildCachedDirectoryXml(string $tagName, string $domain, string $username, string $keyValue): string
    {
        $ttl = (int) config('freeswitch.xml_handler.directory_cache_ttl', 0);

        if ($ttl <= 0) {
            return $this->buildDirectoryXml($tagName, $domain, $username, $keyValue);
        }

        $tenantId = $this->tenantManager->getTenantId();

        if ($tenantId === null) {
            return $this->buildDirectoryXml($tagName, $domain, $username, $keyValue);
        }

        $cacheStore = config('freeswitch.xml_handler.directory_cache_store');
        $cache = is_string($cacheStore) && $cacheStore !== ''
            ? Cache::store($cacheStore)
            : Cache::driver();

        return $cache->remember(
            $this->directoryCacheKey((string) $tenantId, $tagName, $domain, $username, $keyValue),
            $ttl,
            fn (): string => $this->buildDirectoryXml($tagName, $domain, $username, $keyValue),
        );
    }

    /**
     * Build a stable cache key for directory XML lookups.
     */
    private function directoryCacheKey(string $tenantId, string $tagName, string $domain, string $username, string $keyValue): string
    {
        return 'freeswitch:xml-handler:directory:'.sha1(implode('|', [
            $tenantId,
            $tagName,
            $domain,
            $username,
            $keyValue,
            (string) RoutingCacheVersion::get((int) $tenantId),
        ]));
    }

    /**
     * Build a single user XML entry for the FreeSWITCH directory.
     *
     * Creates a <user> element with the SIP account's authentication
     * params (password), the voicemail storage param, and variables
     * (user_context, tenant_id).
     *
     * @param  \Illuminate\Support\Collection<string, Extension>|null  $extensions
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, ExtensionSetting>>|null  $extensionSettings
     */
    private function buildUserEntryXml(
        SipAccount $account,
        ?\Illuminate\Support\Collection $extensions = null,
        ?\Illuminate\Support\Collection $extensionSettings = null
    ): string {
        $writer = new XmlWriter;
        $writer->openTag('user', ['id' => $account->auth_username], 12);
        $writer->openTag('params', [], 14);
        $writer->selfClosingTag('param', ['name' => 'password', 'value' => $account->auth_password], 16);

        // mod_voicemail resolves the deposit storage dir from this param on
        // modern builds (and from the variable below on older builds); both
        // point at the tenant's managed voicemail root so deposits stay inside
        // the managed media tree and the listener can register them.
        $vmStorageDir = rtrim((string) config('media-storage.store_root'), '/').'/runtime/'.$account->tenant_id.'/voicemail-message';
        $writer->selfClosingTag('param', ['name' => 'vm-domain-storage-dir', 'value' => $vmStorageDir], 16);
        $writer->closeTag('params', 14);

        $writer->openTag('variables', [], 14);
        $writer->selfClosingTag('variable', ['name' => 'user_context', 'value' => $account->user_context ?? 'default'], 16);
        $writer->selfClosingTag('variable', ['name' => 'tenant_id', 'value' => (string) $account->tenant_id], 16);
        $writer->selfClosingTag('variable', ['name' => 'vm-domain-storage-dir', 'value' => $vmStorageDir], 16);

        if ($account->extension_id !== null) {
            $writer->selfClosingTag('variable', ['name' => 'extension_id', 'value' => $account->extension_id], 16);

            /** @var Extension|null $extension */
            $extension = $extensions !== null
                ? $extensions->get($account->extension_id)
                : Extension::withoutGlobalScope('tenant')->find($account->extension_id);
            $extensionVars = [];

            if ($extension !== null) {
                if ($extension->effective_caller_id_name !== null && $extension->effective_caller_id_name !== '') {
                    $extensionVars['effective_caller_id_name'] = $extension->effective_caller_id_name;
                }
                if ($extension->effective_caller_id_number !== null && $extension->effective_caller_id_number !== '') {
                    $extensionVars['effective_caller_id_number'] = $extension->effective_caller_id_number;
                }
                if ($extension->outbound_caller_id_name !== null && $extension->outbound_caller_id_name !== '') {
                    $extensionVars['outbound_caller_id_name'] = $extension->outbound_caller_id_name;
                }
                if ($extension->outbound_caller_id_number !== null && $extension->outbound_caller_id_number !== '') {
                    $extensionVars['outbound_caller_id_number'] = $extension->outbound_caller_id_number;
                }
            }

            // Allowlisted extension settings override the account defaults.
            $settings = $extensionSettings !== null
                ? $extensionSettings->get($account->extension_id, collect())
                : ExtensionSetting::withoutGlobalScope('tenant')
                    ->where('tenant_id', $account->tenant_id)
                    ->where('extension_id', $account->extension_id)
                    ->whereIn('key', self::ALLOWED_EXTENSION_SETTINGS)
                    ->get();

            foreach ($settings as $setting) {
                $extensionVars[$setting->key] = $setting->value;
            }

            foreach ($extensionVars as $key => $val) {
                $writer->selfClosingTag('variable', ['name' => $key, 'value' => (string) $val], 16);
            }
        }

        $writer->closeTag('variables', 14);
        $writer->closeTag('user', 12);

        return $writer->toString();
    }
}
