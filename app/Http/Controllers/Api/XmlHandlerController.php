<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantDomain;
use App\Services\DialplanContext;
use App\Services\TenantIdentityResolverInterface;
use App\Services\TenantManager;
use App\Services\Xml\DialplanXmlBuilder;
use App\Services\Xml\DirectoryXmlBuilder;
use App\Support\AclConfigurationCache;
use App\Support\Concerns\EscapesXml;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Acl\Models\AccessControl;
use Modules\CallCenters\Models\Queue;
use Modules\Conferences\Models\Conference;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\SipProfiles\Models\SipProfile;
use Modules\SipProfiles\Services\SipProfileServiceInterface;

/**
 * HTTP API endpoint for FreeSWITCH mod_xml_curl.
 *
 * FreeSWITCH's mod_xml_curl module sends HTTP requests to this
 * controller to retrieve dynamic directory, dialplan, and
 * configuration XML. The controller dispatches to the appropriate
 * handler method based on the requested section.
 *
 * Route: GET/POST /api/v1/xml-handler
 *
 * FreeSWITCH mod_xml_curl configuration example:
 *
 *   <configuration name="xml_curl.conf" description="cURL XML Gateway">
 *     <bindings>
 *       <binding name="directory">
 *         <param name="gateway-url"
 *           value="https://pbx.example.com/api/v1/xml-handler"
 *           bindings="directory"/>
 *       </binding>
 *       <binding name="dialplan">
 *         <param name="gateway-url"
 *           value="https://pbx.example.com/api/v1/xml-handler"
 *           bindings="dialplan"/>
 *       </binding>
 *       <binding name="configuration">
 *         <param name="gateway-url"
 *           value="https://pbx.example.com/api/v1/xml-handler"
 *           bindings="configuration"/>
 *       </binding>
 *     </bindings>
 *   </configuration>
 */
class XmlHandlerController extends Controller
{
    use EscapesXml;

    /**
     * Create a new XML handler controller instance.
     */
    public function __construct(
        private readonly TenantManager $tenantManager,
        private readonly TenantIdentityResolverInterface $identityResolver,
        private readonly SipProfileServiceInterface $sipProfileService,
        private readonly MediaStorageServiceInterface $mediaStorage,
        private readonly DirectoryXmlBuilder $directoryBuilder,
        private readonly DialplanXmlBuilder $dialplanBuilder,
    ) {}

    /**
     * Handle the mod_xml_curl request and return the appropriate XML.
     *
     * FreeSWITCH sends query parameters identifying the section
     * (directory, dialplan, configuration), the key (user, domain,
     * pattern), and context information.
     *
     * Common query parameters sent by FreeSWITCH:
     *   - section: directory | dialplan | configuration
     *   - tag_name: domain | user | group (for directory)
     *   - key_name: name (identifies the specific entry)
     *   - key_value: the value to look up
     *   - Event-Calling-Function, FreeSWITCH-Hostname, etc.
     *
     * @param  Request  $request  The HTTP request from FreeSWITCH mod_xml_curl
     * @return Response XML response or error
     */
    public function handle(Request $request): Response
    {
        // Authenticate XML handler requests.
        // When auth is enabled, a valid shared-secret token MUST be provided.
        // Rejects requests when the configured token is blank to prevent
        // the null-token bypass (null === null = true).
        if (config('freeswitch.xml_handler.auth')) {
            $expectedToken = config('freeswitch.xml_handler.token');

            // Fail-closed: reject all requests if auth is enabled but no token is configured
            if (! is_string($expectedToken) || $expectedToken === '') {
                Log::critical('XML Handler: authentication is enabled but no token is configured. Rejecting all requests.');

                return $this->xmlResponse(
                    $this->errorXml(403, 'Access Denied — server token misconfiguration'),
                    403,
                );
            }

            $requestToken = $request->bearerToken()
                ?? $request->header('X-FS-Token')
                ?? $request->query('token');

            // Reject when the request did not provide any token
            if (! is_string($requestToken) || $requestToken === '') {
                Log::warning('XML Handler: authentication failed — no token provided.', [
                    'ip' => $request->ip(),
                ]);

                return $this->xmlResponse(
                    $this->errorXml(403, 'Access Denied'),
                    403,
                );
            }

            // Timing-safe comparison to prevent timing attacks against the shared secret
            if (! hash_equals($expectedToken, $requestToken)) {
                Log::warning('XML Handler: authentication failed — token mismatch.', [
                    'ip' => $request->ip(),
                ]);

                return $this->xmlResponse(
                    $this->errorXml(403, 'Access Denied'),
                    403,
                );
            }
        }

        $section = $request->input('section', 'directory');
        $hostname = $request->input('hostname')
            ?? $request->input('FreeSWITCH-Hostname')
            ?? '';

        $this->logRequestDebug('XML Handler request.', [
            'section' => $section,
            'hostname' => $hostname,
            'params' => $request->except(['token', 'password', 'secret']),
        ]);

        return match ($section) {
            'directory' => $this->handleDirectory($request),
            'dialplan' => $this->handleDialplan($request),
            'configuration' => $this->handleConfiguration($request),
            'phrases' => $this->handlePhrases($request),
            default => $this->xmlResponse(
                $this->errorXml(404, "Unknown section: {$section}"),
                404,
            ),
        };
    }

    /**
     * Handle directory section requests.
     *
     * FreeSWITCH requests directory XML to resolve users/endpoints.
     * The controller resolves the target tenant and returns the
     * appropriate user/domain directory entry.
     *
     * Key query parameters:
     *   - tag_name: domain | user | group | params
     *   - key_name: name | id
     *   - key_value: the value to look up (e.g., auth_username, domain name)
     *   - domain: the SIP domain
     *   - sip_auth_username: the authenticating username
     *   - sip_auth_realm: the authenticating realm
     */
    private function handleDirectory(Request $request): Response
    {
        $tagName = $request->input('tag_name', 'user');
        $keyValue = $request->input('key_value', '');
        $domain = $request->input('domain', '');
        $sipAuthUsername = $request->input('sip_auth_username', $request->input('user', ''));

        // Resolve tenant identity using multi-factor resolution:
        //   1. Domain + SIP auth username (handles shared domains)
        //   2. Domain only (works when domain is unique to one tenant)
        //   3. Fail closed when domain is shared without enough identity data
        $identity = $this->identityResolver->resolveFromSipAuth($sipAuthUsername, $domain !== '' ? $domain : null)
            ?? $this->identityResolver->resolveFromDomain($domain);

        if ($identity !== null) {
            $this->tenantManager->setTenantId($identity->tenantId);

            $this->logRequestDebug('XML Handler: resolved tenant for directory request.', [
                'domain' => $domain,
                'sip_auth_username' => $sipAuthUsername,
                'tenant_id' => $identity->tenantId,
            ]);
        } else {
            $this->tenantManager->clear();

            Log::warning('XML Handler: could not resolve tenant for directory request.', [
                'domain' => $domain,
                'sip_auth_username' => $sipAuthUsername !== '' ? $sipAuthUsername : null,
            ]);
        }

        // For now, return a base directory structure.
        // Specific tenant/user resolution will be added as domain
        // and SIP account modules are built in later phases.
        $xml = $this->directoryBuilder->buildCachedDirectoryXml($tagName, $domain, $sipAuthUsername, $keyValue);

        return $this->xmlResponse($xml);
    }

    /**
     * Handle dialplan section requests.
     *
     * FreeSWITCH requests dialplan XML when it needs to route a call.
     * The dialplan is built dynamically based on the caller's tenant
     * context and the destination number.
     *
     * Key query parameters:
     *   - Caller-Context
     *   - Caller-Destination-Number
     *   - Caller-Caller-ID-Number
     */
    private function handleDialplan(Request $request): Response
    {
        $context = $request->input('Caller-Context', 'public');
        $destination = $request->input('Caller-Destination-Number', '');
        $callerId = $request->input('Caller-Caller-ID-Number', '');
        $domain = $request->input('Caller-Domain', $request->input('domain', ''));

        // Resolve tenant from context string first (tenant_{uuid}_internal/public)
        $dialplanContext = app(DialplanContext::class);
        $tenantIdFromContext = $dialplanContext->parseTenantId($context);

        if ($tenantIdFromContext !== null) {
            $this->tenantManager->setTenantId($tenantIdFromContext);

            $this->logRequestDebug('XML Handler: resolved tenant from dialplan context.', [
                'context' => $context,
                'tenant_id' => $tenantIdFromContext,
            ]);
        } else {
            // Fall back to resolving tenant from the SIP domain
            $identity = $this->identityResolver->resolveFromDomain($domain);

            if ($identity !== null) {
                $this->tenantManager->setTenantId($identity->tenantId);

                $this->logRequestDebug('XML Handler: resolved tenant from domain via resolver.', [
                    'domain' => $domain,
                    'tenant_id' => $identity->tenantId,
                ]);
            } else {
                $this->tenantManager->clear();

                Log::warning('XML Handler: unknown SIP domain and non-tenant context, tenant context cleared.', [
                    'domain' => $domain,
                    'context' => $context,
                ]);
            }
        }

        $xml = $this->dialplanBuilder->buildCachedDialplanXml($context, $destination, $callerId);

        return $this->xmlResponse($xml);
    }

    /**
     * Handle phrases section requests.
     *
     * Serves the interactive PIN prompt macros used by the PIN routing
     * contributor. The prompts are beeps only (tone_stream), so no
     * localization is needed.
     */
    private function handlePhrases(Request $request): Response
    {
        $xml = '<document type="freeswitch/xml">'."\n";
        $xml .= '  <section name="phrases">'."\n";
        $xml .= '    <macros>'."\n";
        $xml .= '      <macro name="pin_number_enter">'."\n";
        $xml .= '        <input pattern="(.*)">'."\n";
        $xml .= '          <match>'."\n";
        // FreeSWITCH's phrase engine reads the function attribute.
        $xml .= '            <action function="play-file" data="tone_stream://%(1000,0,640)"/>'."\n";
        $xml .= '          </match>'."\n";
        $xml .= '        </input>'."\n";
        $xml .= '      </macro>'."\n";
        $xml .= '      <macro name="pin_number_destination">'."\n";
        $xml .= '        <input pattern="(.*)">'."\n";
        $xml .= '          <match>'."\n";
        // FreeSWITCH's phrase engine reads the function attribute.
        $xml .= '            <action function="play-file" data="tone_stream://%(1000,0,640)"/>'."\n";
        $xml .= '          </match>'."\n";
        $xml .= '        </input>'."\n";
        $xml .= '      </macro>'."\n";
        $xml .= '    </macros>'."\n";
        $xml .= '  </section>'."\n";
        $xml .= '</document>';

        return $this->xmlResponse($xml);
    }

    /**
     * Handle configuration section requests.
     *
     * FreeSWITCH can request dynamic configuration through
     * mod_xml_curl, such as SIP profiles, access controls,
     * and module-specific settings.
     *
     * Key query parameters:
     *   - key_name: name (the configuration name)
     *   - key_value: the configuration value
     */
    private function handleConfiguration(Request $request): Response
    {
        $keyName = $request->input('key_name', '');
        $keyValue = $request->input('key_value', '');
        $domain = $request->input(
            'domain',
            $request->input('sip_auth_realm', $request->input('variable_sip_from_host', '')),
        );

        if ($domain !== '') {
            $identity = $this->identityResolver->resolveFromDomain($domain);

            if ($identity !== null) {
                $this->tenantManager->setTenantId($identity->tenantId);

                $this->logRequestDebug('XML Handler: resolved tenant for configuration request.', [
                    'domain' => $domain,
                    'tenant_id' => $identity->tenantId,
                ]);
            } else {
                $this->tenantManager->clear();

                Log::warning('XML Handler: unknown configuration domain, tenant context cleared.', [
                    'domain' => $domain,
                ]);
            }
        } else {
            $this->tenantManager->clear();
        }

        $xml = $this->buildConfigurationXml($keyName, $keyValue, $domain === '');

        return $this->xmlResponse($xml);
    }

    // ────────────────────────────────────────────────────────────
    //  XML Builders — return FreeSWITCH-compatible XML strings
    // ────────────────────────────────────────────────────────────

    /**
     * Build configuration XML for module configuration requests.
     *
     * Returns empty configuration sections by default. Real
     * configuration will be served once relevant modules exist.
     */
    private function buildConfigurationXml(string $keyName, string $keyValue, bool $includeAllTenantConfiguration = false): string
    {
        $configurationName = $this->resolveConfigurationName($keyName, $keyValue);

        return match ($configurationName) {
            'switch.conf' => $this->buildSwitchConfigurationXml(),
            'db.conf' => $this->buildDatabaseBackedConfigurationXml('db.conf', 'LIMIT DB Configuration'),
            'fifo.conf' => $this->buildDatabaseBackedConfigurationXml('fifo.conf', 'FIFO Configuration'),
            'sofia.conf', 'sofia_global_settings' => $this->buildSofiaConfigurationXml(
                $includeAllTenantConfiguration || $this->tenantManager->getTenantId() === null,
            ),
            'ivr.conf' => $this->buildIvrConfigurationXml($includeAllTenantConfiguration),
            'conference.conf' => $this->buildConferenceConfigurationXml($includeAllTenantConfiguration),
            'callcenter.conf' => $this->buildCallCenterConfigurationXml($includeAllTenantConfiguration),
            'local_stream.conf' => $this->buildLocalStreamConfigurationXml(),
            'acl.conf' => $this->buildAclConfigurationXml(),
            default => $this->buildPlaceholderConfigurationXml($keyName, $keyValue),
        };
    }

    /**
     * Return the cached dynamic acl.conf network-lists document.
     */
    private function buildAclConfigurationXml(): string
    {
        return AclConfigurationCache::remember(fn (): string => $this->renderAclConfigurationXml());
    }

    /**
     * Render the acl.conf network-lists from tenant domains and access-control rules.
     *
     * The domains list keeps the $${domain} compat node (single-quoted so PHP
     * does not interpolate it) followed by every enabled tenant domain. User
     * rules render as {tenant_slug}.{rule_name} lists because FreeSWITCH list
     * names are global while rule names are unique only per tenant.
     */
    private function renderAclConfigurationXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<document type="freeswitch/xml">'."\n";
        $xml .= '  <section name="configuration">'."\n";
        $xml .= '    <configuration name="acl.conf" description="Network Lists">'."\n";
        $xml .= '      <network-lists>'."\n";
        $xml .= '        <list name="domains" default="deny">'."\n";
        $xml .= '          <node type="allow" domain="$${domain}"/>'."\n";

        $domains = TenantDomain::query()
            ->where('enabled', true)
            ->whereHas('tenant', fn ($query) => $query->where('enabled', true))
            ->orderBy('domain')
            ->get();

        foreach ($domains as $domain) {
            $xml .= '          <node type="allow" domain="'.$this->escapeXml($domain->domain).'"/>'."\n";
        }

        $xml .= '        </list>'."\n";

        // The acl.conf document is instance-global (FreeSWITCH has one set of
        // network lists), so the tenant global scope must not apply.
        $rules = AccessControl::query()
            ->withoutGlobalScope('tenant')
            ->with(['nodes', 'tenant:id,slug'])
            ->where('enabled', true)
            ->orderBy('tenant_id')
            ->orderBy('name')
            ->get();

        foreach ($rules as $rule) {
            $nodes = $rule->nodes->sortBy('order');

            if ($nodes->isEmpty()) {
                continue;
            }

            $listName = $rule->tenant->slug.'.'.$rule->name;
            $xml .= '        <list name="'.$this->escapeXml($listName).'" default="'.$this->escapeXml($rule->action).'">'."\n";

            foreach ($nodes as $node) {
                $attribute = $node->type === 'domain' ? 'domain' : 'cidr';
                $xml .= '          <node type="allow" '.$attribute.'="'.$this->escapeXml($node->value).'"/>'."\n";
            }

            $xml .= '        </list>'."\n";
        }

        $xml .= '      </network-lists>'."\n";
        $xml .= '    </configuration>'."\n";
        $xml .= '  </section>'."\n";
        $xml .= '</document>';

        return $xml;
    }

    /**
     * Build FreeSWITCH core configuration XML.
     */
    private function buildSwitchConfigurationXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<document type="freeswitch/xml">'."\n";
        $xml .= '  <section name="configuration">'."\n";
        $xml .= '    <configuration name="switch.conf" description="Core Configuration">'."\n";
        $xml .= '      <settings>'."\n";
        $xml .= '        <param name="loglevel" value="'.$this->escapeXml($this->switchLogLevel()).'"/>'."\n";
        $xml .= '        <param name="sessions-per-second" value="'.$this->switchSessionsPerSecond().'"/>'."\n";

        $driver = $this->freeSwitchDatabaseDriver();
        $coreDsn = $this->freeSwitchDatabaseDsn();
        $coreDbName = $this->nullableConfigString('freeswitch.database.core_db_name');

        if ($driver !== 'sqlite' && $coreDsn !== null) {
            $xml .= '        <param name="core-db-dsn" value="'.$this->escapeXml($coreDsn).'"/>'."\n";
        } elseif ($coreDbName !== null) {
            $xml .= '        <param name="core-db-name" value="'.$this->escapeXml($coreDbName).'"/>'."\n";
        }

        $xml .= '        <param name="auto-create-schemas" value="'.$this->booleanConfigValue('freeswitch.database.auto_create_schemas').'"/>'."\n";

        $autoClearSql = $this->nullableConfigBoolean('freeswitch.database.auto_clear_sql');
        if ($autoClearSql !== null) {
            $xml .= '        <param name="auto-clear-sql" value="'.$autoClearSql.'"/>'."\n";
        }

        if (config('freeswitch.database.core_non_sqlite_db_required', false)) {
            $xml .= '        <param name="core-non-sqlite-db-required" value="true"/>'."\n";
        }

        if (config('freeswitch.database.odbc_skip_autocommit_flip', false)) {
            $xml .= '        <param name="odbc-skip-autocommit-flip" value="true"/>'."\n";
        }

        $xml .= '      </settings>'."\n";
        $xml .= '    </configuration>'."\n";
        $xml .= '  </section>'."\n";
        $xml .= '</document>';

        return $xml;
    }

    /**
     * Return a FreeSWITCH-safe core log level.
     */
    private function switchLogLevel(): string
    {
        $configuredLevel = strtolower((string) config('freeswitch.switch.loglevel', 'notice'));
        $allowedLevels = ['debug', 'info', 'notice', 'warning', 'err', 'crit', 'alert'];

        return in_array($configuredLevel, $allowedLevels, true) ? $configuredLevel : 'notice';
    }

    /**
     * Return the maximum number of new FreeSWITCH sessions allowed each second.
     */
    private function switchSessionsPerSecond(): int
    {
        return max(1, (int) config('freeswitch.switch.sessions_per_second', 60));
    }

    /**
     * Build configuration XML for FreeSWITCH modules that use an odbc-dsn parameter.
     */
    private function buildDatabaseBackedConfigurationXml(string $name, string $description): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<document type="freeswitch/xml">'."\n";
        $xml .= '  <section name="configuration">'."\n";
        $xml .= '    <configuration name="'.$this->escapeXml($name).'" description="'.$this->escapeXml($description).'">'."\n";
        $xml .= '      <settings>'."\n";

        $dsn = $this->freeSwitchModuleDatabaseDsn();
        if ($dsn !== null) {
            $xml .= '        <param name="odbc-dsn" value="'.$this->escapeXml($dsn).'"/>'."\n";
        } else {
            $xml .= '        <!-- FreeSWITCH will use its default SQLite database for this module. -->'."\n";
        }

        $xml .= '      </settings>'."\n";
        $xml .= '    </configuration>'."\n";
        $xml .= '  </section>'."\n";
        $xml .= '</document>';

        return $xml;
    }

    /**
     * Build Sofia configuration XML from global config and tenant profiles.
     */
    private function buildSofiaConfigurationXml(bool $includeAllProfiles = false): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<document type="freeswitch/xml">'."\n";
        $xml .= '  <section name="configuration">'."\n";
        $xml .= '    <configuration name="sofia.conf" description="Sofia Endpoint">'."\n";
        $xml .= '      <global_settings>'."\n";

        foreach ($this->sofiaGlobalSettings() as $name => $value) {
            $xml .= '        <param name="'.$this->escapeXml($name).'" value="'.$this->escapeXml($value).'"/>'."\n";
        }

        $xml .= '      </global_settings>'."\n";
        $xml .= '      <profiles>'."\n";

        $tenantId = $this->tenantManager->getTenantId();

        // Include all enabled profiles. When a specific tenant is resolved
        // from a domain, include that tenant's profiles. When no tenant is
        // resolved, include all profiles. This ensures gateways registered
        // on other tenants sharing the same SIP domain are available to
        // FreeSWITCH (e.g. an emergency gateway on a load-test tenant
        // sharing the production IP).
        /** @var Collection<int, SipProfile> $profiles */
        $profiles = SipProfile::withoutGlobalScope('tenant')
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        if ($profiles->isEmpty()) {
            $xml .= '        <!-- No tenant domain resolved for Sofia profiles. -->'."\n";
        }

        foreach ($profiles as $profile) {
            $xml .= $this->indentXml($this->sipProfileService->generateConfig($profile), 8);
        }

        $xml .= '      </profiles>'."\n";
        $xml .= '    </configuration>'."\n";
        $xml .= '  </section>'."\n";
        $xml .= '</document>';

        return $xml;
    }

    /**
     * Return Sofia global settings keyed by FreeSWITCH param name.
     *
     * @return array<string, string>
     */
    private function sofiaGlobalSettings(): array
    {
        $settings = [
            'log-level' => config('freeswitch.sofia.log_level', '0'),
            'auto-restart' => config('freeswitch.sofia.auto_restart', true),
            'debug-presence' => config('freeswitch.sofia.debug_presence', false),
            'capture-server' => config('freeswitch.sofia.capture_server', ''),
            'inbound-reg-in-new-thread' => config('freeswitch.sofia.inbound_reg_in_new_thread', true),
            'max-reg-threads' => config('freeswitch.sofia.max_reg_threads', 8),
        ];

        return collect($settings)
            ->map(fn (mixed $value): string => $this->freeSwitchScalarValue($value))
            ->all();
    }

    /**
     * Convert PHP config scalars to FreeSWITCH-compatible XML values.
     */
    private function freeSwitchScalarValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * Indent generated XML fragments.
     */
    private function indentXml(string $xml, int $spaces): string
    {
        $padding = str_repeat(' ', $spaces);

        return collect(explode("\n", $xml))
            ->map(fn (string $line): string => $padding.$line)
            ->implode("\n")."\n";
    }

    /**
     * Build IVR configuration XML from tenant IVR menu data.
     *
     * Each enabled IVR menu becomes a FreeSWITCH <menu> entry with
     * greeting, digit length, timeout, max failures, and digit-action
     * bindings from the menu's options.
     */
    private function buildIvrConfigurationXml(bool $includeAllMenus = false): string
    {
        $tenantId = $this->tenantManager->getTenantId();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<document type="freeswitch/xml">'."\n";
        $xml .= '  <section name="configuration">'."\n";
        $xml .= '    <configuration name="ivr.conf" description="IVR menus">'."\n";
        $xml .= '      <menus>'."\n";

        /** @var Collection<int, IvrMenu> $menus */
        $menus = IvrMenu::withoutGlobalScope('tenant')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            // Fail closed: with no tenant context and no include-all flag, no menus are served.
            ->when($tenantId === null && ! $includeAllMenus, fn ($query) => $query->whereRaw('1 = 0'))
            ->where('enabled', true)
            ->with(['options', 'mediaAsset'])
            ->orderBy('name')
            ->get();

        foreach ($menus as $menu) {
            $safeName = $this->escapeXml($menu->name);
            $greeting = $this->managedGreetingPath($menu->mediaAsset?->id, $menu->greeting);
            $digitLen = $menu->digit_length > 0 ? $menu->digit_length : 1;
            // FreeSWITCH expects the IVR menu timeout in milliseconds.
            $timeout = ($menu->timeout > 0 ? $menu->timeout : 10) * 1000;
            $maxFailures = $menu->max_failures > 0 ? $menu->max_failures : 3;

            $xml .= '        <menu name="'.$safeName.'"';
            $xml .= ' greet-long="'.$this->escapeXml($greeting).'"';
            $xml .= ' digit-len="'.$digitLen.'"';
            $xml .= ' timeout="'.$timeout.'"';
            $xml .= ' max-failures="'.$maxFailures.'">'."\n";

            foreach ($menu->options as $option) {
                if (! $option->enabled) {
                    continue;
                }

                $safeDigit = $this->escapeXml($option->digit);
                $safeAction = $this->escapeXml($option->action);
                $safeData = $option->action_data !== null
                    ? $this->escapeXml($option->action_data)
                    : '';

                $xml .= '          <entry action="menu-exec-app"';
                $xml .= ' digits="'.$safeDigit.'"';
                $xml .= ' param="'.$safeAction;
                if ($safeData !== '') {
                    $xml .= ' '.$safeData;
                }
                $xml .= '"/>'."\n";
            }

            $xml .= '        </menu>'."\n";
        }

        if ($menus->isEmpty()) {
            $xml .= '        <!-- No enabled IVR menus for this tenant. -->'."\n";
        }

        $xml .= '      </menus>'."\n";
        $xml .= '    </configuration>'."\n";
        $xml .= '  </section>'."\n";
        $xml .= '</document>';

        return $xml;
    }

    /** Resolve a local managed greeting and omit missing media from FreeSWITCH XML. */
    private function managedGreetingPath(?string $mediaAssetId, ?string $legacyPath): string
    {
        if ($mediaAssetId !== null) {
            try {
                return $this->mediaStorage->resolveLocalPath($mediaAssetId) ?? '';
            } catch (\RuntimeException) {
                return '';
            }
        }

        return $legacyPath ?? '';
    }

    /**
     * Build conference configuration XML from tenant conference data.
     */
    private function buildConferenceConfigurationXml(bool $includeAllConferences = false): string
    {
        $tenantId = $this->tenantManager->getTenantId();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<document type="freeswitch/xml">'."\n";
        $xml .= '  <section name="configuration">'."\n";
        $xml .= '    <configuration name="conference.conf" description="Conference rooms">'."\n";
        $xml .= '      <profiles>'."\n";

        /** @var Collection<int, Conference> $conferences */
        $conferences = Conference::withoutGlobalScope('tenant')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            // Fail closed: with no tenant context and no include-all flag, no conferences are served.
            ->when($tenantId === null && ! $includeAllConferences, fn ($query) => $query->whereRaw('1 = 0'))
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        foreach ($conferences as $conf) {
            $safeName = $this->escapeXml($conf->name);
            $safeProfile = $this->escapeXml($conf->profile ?? 'default');
            $maxMembers = $conf->max_members > 0 ? $conf->max_members : 50;

            $xml .= '        <profile name="'.$safeName.'">'."\n";
            $xml .= '          <param name="conference-profile" value="'.$safeProfile.'"/>'."\n";
            $xml .= '          <param name="max-members" value="'.$maxMembers.'"/>'."\n";

            if ($conf->pin !== null && $conf->pin !== '') {
                $xml .= '          <param name="pin" value="'.$this->escapeXml($conf->pin).'"/>'."\n";
            }

            $xml .= '        </profile>'."\n";
        }

        if ($conferences->isEmpty()) {
            $xml .= '        <!-- No enabled conference rooms for this tenant. -->'."\n";
        }

        $xml .= '      </profiles>'."\n";
        $xml .= '    </configuration>'."\n";
        $xml .= '  </section>'."\n";
        $xml .= '</document>';

        return $xml;
    }

    /**
     * Build call center configuration XML from tenant queue data.
     */
    private function buildCallCenterConfigurationXml(bool $includeAllQueues = false): string
    {
        $tenantId = $this->tenantManager->getTenantId();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<document type="freeswitch/xml">'."\n";
        $xml .= '  <section name="configuration">'."\n";
        $xml .= '    <configuration name="callcenter.conf" description="Call Center">'."\n";
        $xml .= '      <queues>'."\n";

        /** @var Collection<int, Queue> $queues */
        $queues = Queue::withoutGlobalScope('tenant')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            // Fail closed: with no tenant context and no include-all flag, no queues are served.
            ->when($tenantId === null && ! $includeAllQueues, fn ($query) => $query->whereRaw('1 = 0'))
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        foreach ($queues as $queue) {
            $safeName = $this->escapeXml($queue->name.'@default');
            $safeStrategy = $this->escapeXml($queue->strategy ?? 'longest-idle-agent');
            $timeout = $queue->timeout > 0 ? $queue->timeout : 30;
            $moh = $queue->music_on_hold ?? '';

            $xml .= '        <queue name="'.$safeName.'">'."\n";
            $xml .= '          <param name="strategy" value="'.$safeStrategy.'"/>'."\n";
            $xml .= '          <param name="moh-sound" value="'.$this->escapeXml($moh).'"/>'."\n";
            $xml .= '          <param name="timeout" value="'.$timeout.'"/>'."\n";
            $xml .= '        </queue>'."\n";
        }

        if ($queues->isEmpty()) {
            $xml .= '        <!-- No enabled call center queues for this tenant. -->'."\n";
        }

        $xml .= '      </queues>'."\n";
        $xml .= '    </configuration>'."\n";
        $xml .= '  </section>'."\n";
        $xml .= '</document>';

        return $xml;
    }

    /**
     * Build music-on-hold stream configuration for packaged FreeSWITCH media.
     *
     * FreeSWITCH sound packages install the default music files under
     * $${sounds_dir}/music/{rate}. Queues can then reference
     * local_stream://moh or a rate-specific stream.
     */
    private function buildLocalStreamConfigurationXml(): string
    {
        $streams = [
            'default' => ['rate' => 8000, 'interval' => 20],
            'moh/8000' => ['rate' => 8000, 'interval' => 20],
            'moh/16000' => ['rate' => 16000, 'interval' => 20],
            'moh/32000' => ['rate' => 32000, 'interval' => 20],
            // 48 kHz uses a shorter 10 ms packet interval to keep RTP frames within the default size.
            'moh/48000' => ['rate' => 48000, 'interval' => 10],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<document type="freeswitch/xml">'."\n";
        $xml .= '  <section name="configuration">'."\n";
        $xml .= '    <configuration name="local_stream.conf" description="Local Stream">'."\n";

        foreach ($streams as $name => $settings) {
            $rate = $settings['rate'];

            $xml .= '      <directory name="'.$this->escapeXml($name).'" path="$${sounds_dir}/music/'.$rate.'">'."\n";
            $xml .= '        <param name="rate" value="'.$rate.'"/>'."\n";
            $xml .= '        <param name="shuffle" value="true"/>'."\n";
            $xml .= '        <param name="channels" value="1"/>'."\n";
            $xml .= '        <param name="interval" value="'.$settings['interval'].'"/>'."\n";
            $xml .= '        <param name="timer-name" value="soft"/>'."\n";
            $xml .= '      </directory>'."\n";
        }

        $xml .= '    </configuration>'."\n";
        $xml .= '  </section>'."\n";
        $xml .= '</document>';

        return $xml;
    }

    /**
     * Build placeholder configuration XML for unsupported configuration files.
     *
     * Only returned for configs that are intentionally deferred (acl.conf,
     * modules.conf, cdr_csv.conf). Configs with enough module data (ivr.conf,
     * conference.conf, callcenter.conf) have dedicated generators.
     */
    private function buildPlaceholderConfigurationXml(string $keyName, string $keyValue): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<document type="freeswitch/xml">'."\n";
        $xml .= '  <section name="configuration">'."\n";
        $xml .= '    <!-- Configuration for '.$this->escapeXml($keyName).'='.$this->escapeXml($keyValue).' will be served dynamically -->'."\n";
        $xml .= '  </section>'."\n";
        $xml .= '</document>';

        return $xml;
    }

    /**
     * Resolve the requested FreeSWITCH configuration file name.
     */
    private function resolveConfigurationName(string $keyName, string $keyValue): string
    {
        if ($keyName === 'name' && $keyValue !== '') {
            return $keyValue;
        }

        return $keyName;
    }

    /**
     * Get the configured FreeSWITCH database driver.
     */
    private function freeSwitchDatabaseDriver(): string
    {
        return strtolower((string) config('freeswitch.database.driver', 'sqlite'));
    }

    /**
     * Get the DSN used by FreeSWITCH core database settings.
     */
    private function freeSwitchDatabaseDsn(): ?string
    {
        $configuredDsn = $this->nullableConfigString('freeswitch.database.dsn');
        if ($configuredDsn !== null) {
            return $configuredDsn;
        }

        return match ($this->freeSwitchDatabaseDriver()) {
            'mariadb', 'mysql' => $this->buildMariaDbDsn(),
            'pgsql', 'postgres', 'postgresql' => $this->buildPostgresDsn(),
            'odbc' => $this->nullableConfigString('freeswitch.database.odbc_dsn'),
            default => null,
        };
    }

    /**
     * Get the DSN used by FreeSWITCH module odbc-dsn parameters.
     */
    private function freeSwitchModuleDatabaseDsn(): ?string
    {
        return $this->nullableConfigString('freeswitch.database.odbc_dsn')
            ?? $this->freeSwitchDatabaseDsn();
    }

    /**
     * Build a native MariaDB DSN from configured connection parts.
     */
    private function buildMariaDbDsn(): string
    {
        $host = (string) config('freeswitch.database.host', '127.0.0.1');
        $port = (int) config('freeswitch.database.port', 3306);
        $database = (string) config('freeswitch.database.database', 'freeswitch');
        $username = (string) config('freeswitch.database.username', 'freeswitch');
        $password = (string) config('freeswitch.database.password', '');

        return "mariadb://Server={$host};Port={$port};Database={$database};Uid={$username};Pwd={$password};";
    }

    /**
     * Build a native PostgreSQL DSN from configured connection parts.
     */
    private function buildPostgresDsn(): string
    {
        $host = (string) config('freeswitch.database.host', '127.0.0.1');
        $port = (int) config('freeswitch.database.port', 5432);
        $database = (string) config('freeswitch.database.database', 'freeswitch');
        $username = (string) config('freeswitch.database.username', 'freeswitch');
        $password = (string) config('freeswitch.database.password', '');
        $options = (string) config('freeswitch.database.options', '');

        return "pgsql://hostaddr={$host} port={$port} dbname={$database} user={$username} password={$password} options={$options}";
    }

    /**
     * Return a nullable string config value, treating blank strings as null.
     */
    private function nullableConfigString(string $key): ?string
    {
        $value = config($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * Return a FreeSWITCH-compatible boolean string for a config value.
     */
    private function booleanConfigValue(string $key): string
    {
        return config($key, false) ? 'true' : 'false';
    }

    /**
     * Return a nullable FreeSWITCH-compatible boolean string for optional config.
     */
    private function nullableConfigBoolean(string $key): ?string
    {
        $value = config($key);

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    // ────────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────────

    /**
     * Build an error XML document for FreeSWITCH.
     */
    private function errorXml(int $code, string $message): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<document type="freeswitch/xml">'."\n";
        $xml .= '  <section name="result">'."\n";
        $xml .= '    <result status="error" code="'.$code.'" message="'.$this->escapeXml($message).'"/>'."\n";
        $xml .= '  </section>'."\n";
        $xml .= '</document>';

        return $xml;
    }

    /**
     * Log successful XML handler request diagnostics when explicitly enabled.
     */
    private function logRequestDebug(string $message, array $context = []): void
    {
        if (config('freeswitch.xml_handler.log_requests', false) === true) {
            Log::debug($message, $context);
        }
    }

    /**
     * Create an XML response with the correct Content-Type header.
     */
    private function xmlResponse(string $xml, int $status = 200): Response
    {
        return response($xml, $status, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
