<?php

declare(strict_types=1);

namespace App\Services\Xml;

use App\Services\TenantManager;
use App\Support\Concerns\EscapesXml;
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
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<document type="freeswitch/xml">'."\n";

        $tenantId = $this->tenantManager->getTenantId();

        if ($tagName === 'domain') {
            $xml .= '  <section name="directory">'."\n";
            $xml .= '    <domain name="'.$this->escapeXml($domain).'">'."\n";
            $xml .= '      <params>'."\n";
            $xml .= '        <param name="dial-string" value="{presence_id=\${dialed_user}@\${dialed_domain}}\${sofia_contact(\${dialed_user}@\${dialed_domain})}"/>'."\n";
            $xml .= '      </params>'."\n";
            $xml .= '      <groups>'."\n";
            $xml .= '        <group name="default">'."\n";
            $xml .= '          <users>'."\n";

            if ($tenantId !== null) {
                /** @var Collection<int, SipAccount> $accounts */
                $accounts = SipAccount::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('enabled', true)
                    ->get();

                foreach ($accounts as $account) {
                    $xml .= $this->buildUserEntryXml($account);
                }
            }

            $xml .= '          </users>'."\n";
            $xml .= '        </group>'."\n";
            $xml .= '      </groups>'."\n";
            $xml .= '    </domain>'."\n";
            $xml .= '  </section>'."\n";
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
                    $xml .= '  <section name="directory">'."\n";
                    $xml .= $this->buildUserEntryXml($account);
                    $xml .= '  </section>'."\n";
                }
            }

            if (! $found) {
                $xml .= '  <section name="directory">'."\n";
                $xml .= '    <!-- User not found: '.$this->escapeXml($lookupUsername).' -->'."\n";
                $xml .= '  </section>'."\n";
            }
        } else {
            $xml .= '  <section name="directory">'."\n";
            $xml .= '    <'.$this->escapeXml($tagName).' name="'.$this->escapeXml($keyValue).'"/>'."\n";
            $xml .= '  </section>'."\n";
        }

        $xml .= '</document>';

        return $xml;
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
        ]));
    }

    /**
     * Build a single user XML entry for the FreeSWITCH directory.
     *
     * Creates a <user> element with the SIP account's authentication
     * params (password), the voicemail storage param, and variables
     * (user_context, tenant_id).
     */
    private function buildUserEntryXml(SipAccount $account): string
    {
        $xml = '            <user id="'.$this->escapeXml($account->auth_username).'">'."\n";
        $xml .= '              <params>'."\n";
        $xml .= '                <param name="password" value="'.$this->escapeXml($account->auth_password).'"/>'."\n";

        // mod_voicemail resolves the deposit storage dir from this param on
        // modern builds (and from the variable below on older builds); both
        // point at the tenant's managed voicemail root so deposits stay inside
        // the managed media tree and the listener can register them.
        $xml .= '                <param name="vm-domain-storage-dir" value="'.$this->escapeXml(
            rtrim((string) config('media-storage.store_root'), '/').'/runtime/'.$account->tenant_id.'/voicemail-message'
        ).'"/>'."\n";
        $xml .= '              </params>'."\n";
        $xml .= '              <variables>'."\n";
        $xml .= '                <variable name="user_context" value="'.$this->escapeXml($account->user_context ?? 'default').'"/>'."\n";
        $xml .= '                <variable name="tenant_id" value="'.$this->escapeXml((string) $account->tenant_id).'"/>'."\n";
        $xml .= '                <variable name="vm-domain-storage-dir" value="'.$this->escapeXml(
            rtrim((string) config('media-storage.store_root'), '/').'/runtime/'.$account->tenant_id.'/voicemail-message'
        ).'"/>'."\n";

        if ($account->extension_id !== null) {
            $xml .= '                <variable name="extension_id" value="'.$this->escapeXml($account->extension_id).'"/>'."\n";

            /** @var Extension|null $extension */
            $extension = Extension::withoutGlobalScope('tenant')->find($account->extension_id);
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
            $extensionSettings = ExtensionSetting::withoutGlobalScope('tenant')
                ->where('tenant_id', $account->tenant_id)
                ->where('extension_id', $account->extension_id)
                ->whereIn('key', self::ALLOWED_EXTENSION_SETTINGS)
                ->get();

            foreach ($extensionSettings as $setting) {
                $extensionVars[$setting->key] = $setting->value;
            }

            foreach ($extensionVars as $key => $val) {
                $xml .= '                <variable name="'.$this->escapeXml($key).'" value="'.$this->escapeXml((string) $val).'"/>'."\n";
            }
        }

        $xml .= '              </variables>'."\n";
        $xml .= '            </user>'."\n";

        return $xml;
    }
}
