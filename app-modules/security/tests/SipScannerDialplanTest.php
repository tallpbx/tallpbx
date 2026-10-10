<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Modules\Security\Services\SipScannerDialplanContributor;
use Modules\Security\Support\SipScannerSignatures;

/**
 * Feature tests for the SIP scanner detection dialplan contributor.
 *
 * The contributor renders the early public-context conditions that match
 * scanner signatures and emit the tallpbx::sip_scanner_detected event,
 * carrying the true socket peer address and leaving all packet blocking to
 * nftables.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
    $this->contributor = new SipScannerDialplanContributor;
});

it('runs before call blocks and emits detection for public contexts only', function (): void {
    // After emergency (10), before call blocks (20) and every routing stage.
    expect($this->contributor->getDialplanPriority())->toBe(15);

    $public = $this->contributor->generateDialplanXml(1, 'tenant_abc_public', '1234');
    $internal = $this->contributor->generateDialplanXml(1, 'tenant_abc_internal', '1234');

    expect($public)->not->toBeNull()
        ->and($internal)->toBeNull();

    // The curated base renders as two high-confidence conditions (User-Agent
    // and From-user) plus the low-confidence third condition. preg_quote
    // escapes the dash in 'friendly-scanner', which stays regex-equivalent.
    expect($public)->toContain('<extension name="tallpbx_sip_scanner_detection">')
        ->and($public)->toContain('field="${sip_user_agent}" expression="friendly\-scanner|VaxSIPUserAgent|sipcli|Ozeki"')
        ->and($public)->toContain('field="${sip_from_user}" expression="sipvicious"')
        ->and($public)->toContain('field="${sip_user_agent}" expression="SIP Call"')
        ->and($public)->toContain('Scanner-Confidence=high')
        ->and($public)->toContain('Scanner-Confidence=low');
});

it('emits detection events without responding or hanging up in the dialplan', function (): void {
    $xml = $this->contributor->generateDialplanXml(1, 'tenant_abc_public', '1234');

    // The dialplan acts purely as a sensor: all conditions emit ESL events,
    // and neither responds 403 nor hangs up (packet blocking is handled
    // by nftables to avoid scanner reconnaissance acknowledgment).
    expect($xml)->not->toContain('application="respond"')
        ->and($xml)->not->toContain('application="hangup"')
        ->and($xml)->not->toContain('proto_security_violation')
        ->and(substr_count($xml, 'application="event"'))->toBe(3);
});

it('carries the socket peer address and never a header-derived address', function (): void {
    $xml = $this->contributor->generateDialplanXml(1, 'tenant_abc_public', '1234');

    // Every emitted event uses ${sip_network_ip} — the true remote peer.
    // sip_from_host and sip_via_host are attacker-controlled and must never
    // feed the ban decision; sip_received_ip is the local interface.
    expect(substr_count($xml, 'Attacker-IP=${sip_network_ip}'))->toBe(3)
        ->and($xml)->not->toContain('sip_from_host')
        ->and($xml)->not->toContain('sip_via_host')
        ->and($xml)->not->toContain('sip_received_ip');
});

it('escapes custom signatures as literal XML-safe substrings', function (): void {
    // 'Zoiper.' and 'a|b' prove regex metacharacters stay literal; 'x<y&z'
    // proves XML metacharacters cannot break out of the expression attribute.
    SipScannerSignatures::saveCustom(['Zoiper.', 'a|b', 'x<y&z']);

    $xml = $this->contributor->generateDialplanXml(1, 'tenant_abc_public', '1234');

    expect($xml)->toContain('Zoiper\.')
        ->and($xml)->toContain('a\|b')
        ->and($xml)->toContain('x\&lt;y&amp;z')
        // Custom entries are high-confidence by definition: they join both
        // high conditions and never the low one.
        ->and($xml)->toContain('sipcli|Ozeki|Zoiper\.|a\|b|x\&lt;y&amp;z')
        ->and($xml)->toContain('sipvicious|Zoiper\.|a\|b|x\&lt;y&amp;z');

    // The low condition stays untouched by custom additions.
    expect($xml)->toContain('expression="SIP Call"');
});

it('reads the custom signature list at render time', function (): void {
    SipScannerSignatures::saveCustom(['Grandstream*']);

    $withCustom = $this->contributor->generateDialplanXml(1, 'tenant_abc_public', '1234');
    expect($withCustom)->toContain('Grandstream\*');

    SipScannerSignatures::saveCustom([]);

    $withoutCustom = $this->contributor->generateDialplanXml(1, 'tenant_abc_public', '1234');
    expect($withoutCustom)->not->toContain('Grandstream')
        // The escaped base pattern is back (preg_quote escapes the dash).
        ->and($withoutCustom)->toContain('friendly\-scanner');
});
