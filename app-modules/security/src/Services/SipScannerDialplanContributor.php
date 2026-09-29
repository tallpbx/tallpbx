<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use App\Contracts\ContextWideDialplanXmlContributor;
use App\Contracts\DialplanGuardXmlContributor;
use Modules\Security\Support\SipScannerSignatures;

/**
 * Contributes the early SIP scanner detection conditions to public contexts.
 *
 * The extension matches incoming requests against the curated signature
 * registry before any routing rule runs:
 *   - High-confidence matches (named scanners) are rejected with 403 and
 *     hung up, and emit a tallpbx::sip_scanner_detected event so the
 *     listener can enforce a kernel ban.
 *   - The low-confidence condition (a generic phrase legitimate softphones
 *     emit) only emits the event — it must never reject a real call.
 *
 * The event always carries ${sip_network_ip}: the true socket peer address.
 * sip_from_host and sip_via_host are attacker-controlled and must never
 * feed the ban decision.
 *
 * Detection and recording are always on; the per-feature enforcement toggle
 * (which gates automatic bans) is evaluated later in PHP by the listener.
 * Custom signatures are merged at render time and inherit the existing
 * dialplan contributor cache TTL — no extra cache plumbing.
 */
class SipScannerDialplanContributor implements ContextWideDialplanXmlContributor, DialplanGuardXmlContributor
{
    /**
     * The event subclass the dialplan emits for every signature match.
     */
    public const EVENT_SUBCLASS = 'tallpbx::sip_scanner_detected';

    /**
     * Runs after emergency (10) and before call blocks (20) and every
     * routing stage, so scanners are rejected before anything connects.
     */
    public function getDialplanPriority(): int
    {
        return 15;
    }

    /**
     * Render the scanner detection extension for public contexts.
     *
     * Returns null for internal contexts: scanner detection only applies to
     * inbound requests from outside the tenant.
     */
    public function generateDialplanXml(int $tenantId, string $context, string $destination): ?string
    {
        if (! str_ends_with($context, '_public')) {
            return null;
        }

        $defaults = SipScannerSignatures::defaults();
        $custom = SipScannerSignatures::custom();

        $xml = '      <extension name="tallpbx_sip_scanner_detection">'."\n";

        // Each confidence tier groups its base patterns by the SIP field
        // they are matched against. Administrator-added signatures are
        // high-confidence by definition and join both high conditions.
        $xml .= $this->conditionsFor(
            $defaults['high'],
            $custom,
            'high',
            withVerdict: true,
        );

        $xml .= $this->conditionsFor(
            $defaults['low'],
            [],
            'low',
            withVerdict: false,
        );

        $xml .= "      </extension>\n";

        return $xml;
    }

    /**
     * Render one condition per field for a confidence tier.
     *
     * @param  array<int, array{field: string, pattern: string}>  $entries  Base entries for this tier
     * @param  array<int, string>  $extraPatterns  Additional high-confidence patterns (custom signatures)
     * @param  string  $confidence  'high' or 'low'
     * @param  bool  $withVerdict  Whether the condition rejects the call (403 + hangup)
     */
    private function conditionsFor(array $entries, array $extraPatterns, string $confidence, bool $withVerdict): string
    {
        // Group the tier's patterns by field so a tier spanning two fields
        // renders one condition per field.
        $byField = [];
        foreach ($entries as $entry) {
            $byField[$entry['field']][] = $entry['pattern'];
        }

        // Custom signatures are matched against the User-Agent and the
        // From-user alike (an administrator adds a literal string, not a
        // field-scoped regex).
        foreach (['User-Agent', 'From'] as $field) {
            if ($extraPatterns !== []) {
                $byField[$field] = array_merge($byField[$field] ?? [], $extraPatterns);
            }
        }

        $xml = '';

        // Render the conditions in a fixed field order (User-Agent first,
        // then From-user) so the generated dialplan is deterministic no
        // matter how the curated base list is ordered.
        foreach (['User-Agent', 'From'] as $field) {
            if (! isset($byField[$field])) {
                continue;
            }

            $patterns = $byField[$field];
            $variable = $field === 'From' ? 'sip_from_user' : 'sip_user_agent';
            $scannerType = $field === 'From' ? 'From-User' : 'User-Agent';

            // The literal FreeSWITCH variable reference, e.g. ${sip_user_agent}.
            $variableRef = '${'.$variable.'}';

            // preg_quote keeps every entry a literal substring; XML-escaping
            // keeps a stored `<` or `&` from breaking the attribute.
            $expression = $this->escapeXml(SipScannerSignatures::escapedRegex($patterns));

            $xml .= "        <condition field=\"{$variableRef}\" expression=\"{$expression}\">\n";

            if ($withVerdict) {
                $xml .= "          <action application=\"set\" data=\"proto_security_violation=1\"/>\n";
            }

            $xml .= "          <action application=\"event\" data=\"Event-Name=CUSTOM,Event-Subclass=".self::EVENT_SUBCLASS
                .",Scanner-Type={$scannerType},Scanner-Value={$variableRef},Scanner-Confidence={$confidence}"
                .',Attacker-IP=${sip_network_ip}"/>'."\n";

            if ($withVerdict) {
                $xml .= "          <action application=\"respond\" data=\"403 Forbidden\"/>\n";
                $xml .= "          <action application=\"hangup\"/>\n";
            }

            $xml .= "        </condition>\n";
        }

        return $xml;
    }

    /**
     * Escape a regular expression for safe inclusion in an XML attribute.
     */
    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
