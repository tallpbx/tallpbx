<?php

declare(strict_types=1);

namespace App\Events\FreeSwitch;

/**
 * Dispatched when the dialplan detects a SIP scanner signature match.
 *
 * Emitted by the tallpbx_sip_scanner_detection dialplan extension with
 * Event-Subclass=tallpbx::sip_scanner_detected. The Attacker-IP header
 * always carries ${sip_network_ip} — the true socket peer address — never
 * a header-derived value, because sip_from_host and sip_via_host are
 * attacker-controlled.
 */
class SipScannerDetected extends CustomEvent
{
    /**
     * The SIP field that carried the matched signature ('User-Agent' or 'From-User').
     */
    public function scannerType(): ?string
    {
        return $this->header('Scanner-Type');
    }

    /**
     * The raw matched value (the offending User-Agent or From-user string).
     */
    public function scannerValue(): ?string
    {
        return $this->header('Scanner-Value');
    }

    /**
     * The confidence tier of the match: 'high' bans on a match, 'low' is
     * recorded and only escalates when several distinct signatures appear.
     * A missing header defaults to high (the high conditions do not need to
     * opt in explicitly).
     */
    public function confidence(): string
    {
        return strtolower((string) ($this->header('Scanner-Confidence') ?? 'high')) === 'low' ? 'low' : 'high';
    }

    /**
     * The offending remote peer address (${sip_network_ip} from the dialplan).
     */
    public function attackerIp(): ?string
    {
        return $this->header('Attacker-IP');
    }
}
