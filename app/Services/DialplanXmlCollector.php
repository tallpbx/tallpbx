<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use App\Contracts\DialplanXmlContributor;
use App\Support\RoutingCacheVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Collects and aggregates dialplan XML from all registered
 * feature module contributors.
 *
 * Each PBX module that implements DialplanXmlContributor registers
 * itself as a tagged service ('dialplan.xml' tag). The collector
 * resolves all contributors and invokes each one, concatenating
 * the non-null results into the final dialplan XML.
 *
 * Contributors are sorted by priority via getDialplanPriority()
 * (lower values appear first). The priority must be implemented
 * by every contributor — this is guaranteed by the interface.
 *
 * This keeps the XmlHandlerController decoupled from individual
 * modules — new contributors are picked up automatically via
 * the container's tag-based service resolution.
 */
class DialplanXmlCollector
{
    /**
     * Reference priority for contributors that should appear
     * at the default position in the dialplan.
     */
    public const DEFAULT_PRIORITY = 100;

    /**
     * Create a collector that skips contributors from disabled modules.
     */
    public function __construct(private ModuleState $moduleState) {}

    /**
     * Collect dialplan XML from all registered contributors.
     *
     * Contributors are sorted by priority (optional getDialplanPriority()
     * method, lower = earlier), then iterated. Contributors that return
     * null (no matching rules) are silently skipped.
     *
     * @param  int  $tenantId  The resolved tenant ID
     * @param  string  $context  The FreeSWITCH context name
     * @param  string  $destination  The called number
     * @return string Concatenated dialplan XML from all contributors
     */
    public function collect(int $tenantId, string $context, string $destination): string
    {
        /** @var DialplanXmlContributor[] $contributors */
        $contributors = iterator_to_array(app()->tagged('dialplan.xml'));

        // Sort by priority (lower = earlier in the dialplan).
        // Every contributor implements getDialplanPriority() per the interface contract.
        usort($contributors, function (DialplanXmlContributor $a, DialplanXmlContributor $b): int {
            return $a->getDialplanPriority() <=> $b->getDialplanPriority();
        });

        $xml = '';

        foreach ($contributors as $contributor) {
            if (! $this->moduleState->isEnabledForClass($contributor)) {
                continue;
            }

            try {
                $fragment = $this->generateContributorXml($contributor, $tenantId, $context, $destination);

                if ($fragment !== null && $fragment !== '') {
                    $xml .= $fragment;
                }
            } catch (\Throwable $e) {
                // A single contributor must not break the entire dialplan.
                // Log the failure and continue with remaining contributors.
                Log::error('Dialplan XML contributor failed.', [
                    'contributor' => get_class($contributor),
                    'tenant_id' => $tenantId,
                    'context' => $context,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $xml;
    }

    /**
     * Generate XML for one contributor, using cache only when it is safe.
     *
     * Context-wide contributors produce the same XML for any destination in
     * a tenant/context pair. Caching them avoids repeated empty-table checks
     * and repeated full-fragment rendering when many calls hit different
     * destination numbers in the same context.
     */
    private function generateContributorXml(DialplanXmlContributor $contributor, int $tenantId, string $context, string $destination): ?string
    {
        if (! $contributor instanceof ContextWideDialplanXmlContributor) {
            return $contributor->generateDialplanXml($tenantId, $context, $destination);
        }

        $ttl = (int) config('freeswitch.xml_handler.dialplan_contributor_cache_ttl', 0);

        if ($ttl <= 0) {
            return $contributor->generateDialplanXml($tenantId, $context, $destination);
        }

        $cacheStore = config('freeswitch.xml_handler.dialplan_cache_store');
        $cache = is_string($cacheStore) && $cacheStore !== ''
            ? Cache::store($cacheStore)
            : Cache::driver();

        $fragment = $cache->remember(
            $this->contributorCacheKey($contributor, $tenantId, $context),
            $ttl,
            fn (): string => $contributor->generateDialplanXml($tenantId, $context, $destination) ?? '',
        );

        return $fragment !== '' ? $fragment : null;
    }

    /**
     * Build the cache key for a context-wide contributor fragment.
     *
     * The routing revision is embedded so DB-driven contributor data (PIN
     * unions, translations, limits) never goes stale beyond the write that
     * changed it — matching the dialplan cache-key contract.
     */
    private function contributorCacheKey(DialplanXmlContributor $contributor, int $tenantId, string $context): string
    {
        return 'freeswitch:xml-handler:dialplan-contributor:'.sha1(implode('|', [
            get_class($contributor),
            (string) $tenantId,
            $context,
            (string) RoutingCacheVersion::get($tenantId),
        ]));
    }
}
