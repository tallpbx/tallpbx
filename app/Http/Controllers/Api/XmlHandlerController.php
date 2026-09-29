<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DialplanContext;
use App\Services\TenantIdentityResolverInterface;
use App\Services\TenantManager;
use App\Services\Xml\DialplanXmlBuilder;
use App\Services\Xml\DirectoryXmlBuilder;
use App\Services\Xml\SofiaConfigXmlBuilder;
use App\Support\Concerns\EscapesXml;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

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
        private readonly DirectoryXmlBuilder $directoryBuilder,
        private readonly DialplanXmlBuilder $dialplanBuilder,
        private readonly SofiaConfigXmlBuilder $configBuilder,
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

        $xml = $this->configBuilder->buildConfigurationXml($keyName, $keyValue, $domain === '');

        return $this->xmlResponse($xml);
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
