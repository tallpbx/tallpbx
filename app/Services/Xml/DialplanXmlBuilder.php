<?php

declare(strict_types=1);

namespace App\Services\Xml;

use App\Services\DialplanXmlCollector;
use App\Services\TenantManager;
use App\Support\Concerns\EscapesXml;
use App\Support\RoutingCacheVersion;
use App\Support\Xml\XmlWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Models\DialplanDetail;
use Modules\NumberTranslations\Services\NumberTranslationServiceInterface;
use Modules\TenantLimits\Models\TenantLimit;

/**
 * Builds the FreeSWITCH dialplan-section XML, including hiredis fallbacks,
 * destination rewriting, and per-tenant caching.
 */
class DialplanXmlBuilder
{
    use EscapesXml;

    /**
     * Create the builder with the services the dialplan rendering needs.
     */
    public function __construct(
        private readonly TenantManager $tenantManager,
        private readonly NumberTranslationServiceInterface $numberTranslationService,
        private readonly DialplanXmlCollector $dialplanXmlCollector,
    ) {}

    /**
     * Build dialplan XML for call routing.
     *
     * When a tenant context is active, builds the dialplan from standard
     * dialplan rules plus feature module contributions (inbound routes,
     * ring groups, IVRs, etc.). Feature contributors are always invoked
     * regardless of whether standard dialplans exist for the context —
     * a tenant can have feature-based routing without synthetic base
     * dialplan records.
     *
     * Falls back to default_no_route only when both standard dialplans
     * AND contributor XML are empty.
     */
    private function buildDialplanXml(string $context, string $destination, string $callerId): string
    {
        $writer = new XmlWriter;
        $writer->startDocument();
        $writer->openTag('document', ['type' => 'freeswitch/xml']);
        $writer->openTag('section', ['name' => 'dialplan'], 2);

        $tenantId = $this->tenantManager->getTenantId();
        $rewrittenDestination = null;

        if ($tenantId !== null) {
            // Number translations rewrite the destination before any routing
            // runs (the FusionPBX mod_translate position). Public context =
            // inbound rules, internal context = outbound rules. This runs on
            // the cache-miss build so cached responses stay query-free.
            $direction = str_ends_with($context, '_public') ? 'inbound' : 'outbound';
            $originalDestination = $destination;
            $destination = $this->numberTranslationService->translate($destination, $direction);

            // A PHP-side rewrite alone never reaches FreeSWITCH: dialplan
            // conditions match the channel's own destination_number at
            // runtime. Materialize the rewrite as a set action so every
            // later condition sees the translated number.
            if ($destination !== $originalDestination) {
                $rewrittenDestination = $destination;
            }

            // Always create a context when tenant is resolved — feature
            // contributors may produce XML even without base dialplans.
            $writer->openTag('context', ['name' => $context], 4);

            // Capture the original caller ID before any auth or routing
            // overwrites it. This is essential for call-block contributors
            // which need the pre-auth From header value. Exported so it
            // survives context transfers (e.g. public → tenant internal).
            $writer->openTag('extension', ['name' => 'capture_orig_caller', 'continue' => 'true'], 6);
            $writer->openTag('condition', [], 8);
            $writer->selfClosingTag('action', ['application' => 'export', 'data' => 'orig_caller_id_number=${caller_id_number}'], 10);
            $writer->closeTag('condition', 8);
            $writer->closeTag('extension', 6);
            $writer->newline();

            // The translated destination must be applied before any feature
            // or standard condition evaluates, so it leads the context.
            if ($rewrittenDestination !== null) {
                $writer->openTag('extension', ['name' => 'translate_destination', 'continue' => 'true'], 6);
                $writer->openTag('condition', [], 8);
                $writer->selfClosingTag('action', ['application' => 'set', 'data' => 'destination_number='.$rewrittenDestination], 10);
                $writer->closeTag('condition', 8);
                $writer->closeTag('extension', 6);
                $writer->newline();
            }

            // Feature module contributions MUST come BEFORE standard
            // dialplan rules so that specific feature extensions (ring groups,
            // voicemail, conferences, etc.) take priority over generic catch-all
            // rules like local_extension which matches any 3-5 digit number.
            //
            // The metadata distinguishes routing contributions from guard
            // contributions (security filters): only a routing contribution
            // may suppress the fail-closed no-route default below.
            $collected = $this->dialplanXmlCollector->collectWithMetadata(
                (int) $tenantId, $context, $destination,
            );
            $contributorXml = $collected['xml'];

            if ($contributorXml !== '') {
                $writer->comment('Feature module dialplan contributions', 6);
                $writer->rawTrustedFragment($contributorXml);
            }

            $standardDialplanXml = $this->buildCachedStandardDialplanXml((int) $tenantId, $context);

            if ($contributorXml !== '' && $standardDialplanXml['has_dialplans']) {
                $writer->newline();
            }

            $writer->rawTrustedFragment($standardDialplanXml['xml']);

            // Default no-route only when neither standard dialplans nor any
            // ROUTING contributor produced rules — never skip contributors
            // just because no base dialplan row exists, and never let a
            // guard contribution (e.g. SIP scanner detection) make an
            // unrouted call look routed.
            if (! $standardDialplanXml['has_dialplans'] && ! $collected['has_routing_contribution']) {
                $writer->openTag('extension', ['name' => 'default_not_found'], 6);
                $writer->openTag('condition', ['field' => 'destination_number', 'expression' => '^(.*)$'], 8);
                $writer->selfClosingTag('action', ['application' => 'hangup', 'data' => 'NO_ROUTE_DESTINATION'], 10);
                $writer->closeTag('condition', 8);
                $writer->closeTag('extension', 6);
            }

            $writer->closeTag('context', 4);
        } else {
            // No tenant context — return default no-route
            $writer->openTag('context', ['name' => $context], 4);
            $writer->openTag('extension', ['name' => 'default_not_found'], 6);
            $writer->openTag('condition', ['field' => 'destination_number', 'expression' => '^(.*)$'], 8);
            $writer->selfClosingTag('action', ['application' => 'log', 'data' => 'INFO [TallPBX] No route found for \${destination_number} in context='.$context], 10);
            $writer->selfClosingTag('action', ['application' => 'hangup', 'data' => 'NO_ROUTE_DESTINATION'], 10);
            $writer->closeTag('condition', 8);
            $writer->closeTag('extension', 6);
            $writer->closeTag('context', 4);
        }

        $writer->closeTag('section', 2);
        $writer->closeTag('document', 0, false);

        return $writer->toString();
    }

    /**
     * Build standard dialplan XML rules for a tenant/context pair.
     *
     * Standard dialplans do not vary by destination number, so this fragment
     * can be reused while mixed dialplan load tests hit many destinations.
     *
     * @return array{xml: string, has_dialplans: bool}
     */
    private function buildStandardDialplanXml(int $tenantId, string $context): array
    {
        $writer = new XmlWriter;

        // Standard dialplan rules ordered by the 'order' column.
        /** @var Collection<int, Dialplan> $dialplans */
        $dialplans = Dialplan::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('context', $context)
            ->where('enabled', true)
            ->with('details')
            ->orderBy('order')
            ->get();

        foreach ($dialplans as $dialplan) {
            $extAttrs = ['name' => $dialplan->name];
            if ($this->shouldContinueExtension($dialplan)) {
                $extAttrs['continue'] = 'true';
            }
            $writer->openTag('extension', $extAttrs, 6);

            foreach ($dialplan->details as $detail) {
                // Validate that the detail tag is a known-safe FreeSWITCH
                // element. Unknown tags are skipped to prevent arbitrary
                // XML injection from database-backed dialplan records.
                if (! $writer->isAllowedTag((string) $detail->tag, config('freeswitch.xml_handler.allowed_dialplan_tags', ['condition', 'action', 'anti-action']))) {
                    Log::warning('Dialplan detail has unknown tag, skipping.', [
                        'detail_id' => $detail->id,
                        'tag' => $detail->tag,
                    ]);

                    continue;
                }

                if ($this->shouldSkipOptionalHiredisDialplanAction($detail->action, $detail->data)) {
                    continue;
                }

                $writer->rawTrustedFragment($this->optionalHiredisDialplanXml($dialplan, $detail, $tenantId));

                $detailAttrs = [];
                if ($detail->field) {
                    $detailAttrs['field'] = $detail->field;
                }
                if ($detail->expression) {
                    $detailAttrs['expression'] = $detail->expression;
                }

                $writer->openTag((string) $detail->tag, $detailAttrs, 8);
                if ($detail->action) {
                    $actionAttrs = ['application' => $detail->action];
                    if ($detail->data) {
                        $actionAttrs['data'] = $this->dialplanActionData($dialplan, $detail->data);
                    }
                    $writer->selfClosingTag('action', $actionAttrs, 10);
                }
                $writer->closeTag((string) $detail->tag, 8);
            }

            $writer->closeTag('extension', 6);
        }

        return [
            'xml' => $writer->toString(),
            'has_dialplans' => $dialplans->isNotEmpty(),
        ];
    }

    /**
     * Build opt-in mod_hiredis actions before local extension bridging.
     */
    private function optionalHiredisDialplanXml(Dialplan $dialplan, DialplanDetail $detail, int $tenantId): string
    {
        if ($dialplan->name !== 'local_extension' || $detail->action !== 'bridge') {
            return '';
        }

        if ($detail->data !== '${sofia_contact($1@${domain_name})}') {
            return '';
        }

        $writer = new XmlWriter;
        $field = (string) $detail->field;
        $expression = (string) $detail->expression;

        if ((bool) config('freeswitch.xml_handler.hiredis_limit_enabled', false)) {
            $limits = TenantLimit::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->orderBy('resource')
                ->get();

            // Per-resource hard limits from DB records; the flat config
            // value remains the fallback when no records exist — and also
            // for the local_extension resource when records only scope
            // other resources, so the default cap never silently vanishes.
            $emitted = false;

            foreach ($limits as $limit) {
                $data = "hiredis default pbx:\${domain_name}:{$limit->resource}:active {$limit->hard_limit}";
                $writer->openTag('condition', ['field' => $field, 'expression' => $expression], 8);
                $writer->selfClosingTag('action', ['application' => 'limit', 'data' => $data], 10);
                $writer->closeTag('condition', 8);
                $emitted = true;
            }

            if (! $emitted || $limits->where('resource', 'local_extension')->isEmpty()) {
                $max = max(1, (int) config('freeswitch.xml_handler.hiredis_limit_max', 100000));
                $data = "hiredis default pbx:\${domain_name}:local_extension:active {$max}";
                $writer->openTag('condition', ['field' => $field, 'expression' => $expression], 8);
                $writer->selfClosingTag('action', ['application' => 'limit', 'data' => $data], 10);
                $writer->closeTag('condition', 8);
            }
        }

        if ((bool) config('freeswitch.xml_handler.hiredis_marker_enabled', false)) {
            $data = 'default set pbx:mod_hiredis:last_call:${uuid} ${caller_id_number}->${destination_number}';
            $writer->openTag('condition', ['field' => $field, 'expression' => $expression], 8);
            $writer->selfClosingTag('action', ['application' => 'hiredis_raw', 'data' => $data], 10);
            $writer->closeTag('condition', 8);
        }

        return $writer->toString();
    }

    /**
     * Decide whether an optional mod_hiredis dialplan action should be hidden.
     */
    private function shouldSkipOptionalHiredisDialplanAction(?string $action, ?string $data): bool
    {
        return match ($action) {
            'limit' => str_starts_with((string) $data, 'hiredis default pbx:'),
            'hiredis_raw' => str_starts_with((string) $data, 'default set pbx:mod_hiredis:last_call:'),
            default => false,
        };
    }

    /**
     * Return extra FreeSWITCH extension attributes for generated dialplans.
     */
    private function dialplanExtensionAttributes(Dialplan $dialplan): string
    {
        return $this->shouldContinueExtension($dialplan) ? ' continue="true"' : '';
    }

    /**
     * Determine if an extension should have continue="true".
     */
    private function shouldContinueExtension(Dialplan $dialplan): bool
    {
        $actions = $dialplan->details
            ->pluck('action')
            ->filter()
            ->unique();

        return $actions->isNotEmpty() && $actions->every(fn (string $action): bool => in_array($action, ['export', 'set'], true));
    }

    /**
     * Return runtime-safe action data for generated dialplan actions.
     */
    private function dialplanActionData(Dialplan $dialplan, string $data): string
    {
        if ($dialplan->name === 'local_extension' && $data === 'user/$1@${domain_name}') {
            return '${sofia_contact($1@${domain_name})}';
        }

        return $data;
    }

    /**
     * Build standard dialplan XML using short-lived context-wide caching.
     *
     * This avoids re-querying MariaDB for the same tenant/context when the
     * final generated XML cache misses for many different destination numbers.
     *
     * @return array{xml: string, has_dialplans: bool}
     */
    private function buildCachedStandardDialplanXml(int $tenantId, string $context): array
    {
        $ttl = (int) config('freeswitch.xml_handler.dialplan_contributor_cache_ttl', 0);

        if ($ttl <= 0) {
            return $this->buildStandardDialplanXml($tenantId, $context);
        }

        $cacheStore = config('freeswitch.xml_handler.dialplan_cache_store');
        $cache = is_string($cacheStore) && $cacheStore !== ''
            ? Cache::store($cacheStore)
            : Cache::driver();

        return $cache->remember(
            $this->standardDialplanCacheKey($tenantId, $context),
            $ttl,
            fn (): array => $this->buildStandardDialplanXml($tenantId, $context),
        );
    }

    /**
     * Build a stable cache key for standard dialplan XML fragments.
     */
    private function standardDialplanCacheKey(int $tenantId, string $context): string
    {
        return 'freeswitch:xml-handler:standard-dialplan:'.sha1(implode('|', [
            (string) $tenantId,
            $context,
            // Bumped by translation/limit writes so DB-driven routing
            // data never goes stale beyond the write itself.
            (string) RoutingCacheVersion::get($tenantId),
            (string) ((bool) config('freeswitch.xml_handler.hiredis_limit_enabled', false)),
            (string) ((int) config('freeswitch.xml_handler.hiredis_limit_max', 100000)),
            (string) ((bool) config('freeswitch.xml_handler.hiredis_marker_enabled', false)),
        ]));
    }

    /**
     * Build dialplan XML, using a short-lived cache for repeated lookups.
     */
    public function buildCachedDialplanXml(string $context, string $destination, string $callerId): string
    {
        $ttl = (int) config('freeswitch.xml_handler.dialplan_cache_ttl', 0);

        if ($ttl <= 0) {
            return $this->buildDialplanXml($context, $destination, $callerId);
        }

        $tenantId = $this->tenantManager->getTenantId();

        if ($tenantId === null) {
            return $this->buildDialplanXml($context, $destination, $callerId);
        }

        $cacheStore = config('freeswitch.xml_handler.dialplan_cache_store');
        $cache = is_string($cacheStore) && $cacheStore !== ''
            ? Cache::store($cacheStore)
            : Cache::driver();

        return $cache->remember(
            $this->dialplanCacheKey((string) $tenantId, $context, $destination),
            $ttl,
            fn (): string => $this->buildDialplanXml($context, $destination, $callerId),
        );
    }

    /**
     * Build a stable cache key for generated dialplan XML.
     *
     * The generated XML currently varies by tenant, context, and destination.
     * Caller ID is passed through to preserve method signatures, but it is not
     * part of generated XML today and is intentionally left out of the key.
     */
    private function dialplanCacheKey(string $tenantId, string $context, string $destination): string
    {
        return 'freeswitch:xml-handler:dialplan:'.sha1(implode('|', [
            $tenantId,
            $context,
            $destination,
            // Bumped by translation/limit writes so DB-driven routing
            // data never goes stale beyond the write itself.
            (string) RoutingCacheVersion::get((int) $tenantId),
            (string) ((bool) config('freeswitch.xml_handler.hiredis_limit_enabled', false)),
            (string) ((int) config('freeswitch.xml_handler.hiredis_limit_max', 100000)),
            (string) ((bool) config('freeswitch.xml_handler.hiredis_marker_enabled', false)),
        ]));
    }
}
