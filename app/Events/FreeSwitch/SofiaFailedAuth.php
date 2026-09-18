<?php

declare(strict_types=1);

namespace App\Events\FreeSwitch;

/**
 * Dispatched when FreeSWITCH Sofia SIP authentication fails (Event-Subclass: sofia::failed_auth).
 *
 * Emitted by FreeSWITCH ESL when an incoming SIP REGISTER or INVITE fails credentials challenge.
 * Used by the Security module to track brute-force attacks and apply automated kernel bans.
 */
class SofiaFailedAuth extends CustomEvent
{
    /**
     * Get the offending remote network IP address.
     */
    public function networkIp(): ?string
    {
        return $this->header('network-ip')
            ?? $this->header('variable_sip_network_ip')
            ?? $this->header('Caller-Network-Addr');
    }

    /**
     * Get the attempted SIP username.
     */
    public function user(): ?string
    {
        return $this->header('user')
            ?? $this->header('variable_sip_auth_username');
    }

    /**
     * Get the SIP authentication realm/domain.
     */
    public function realm(): ?string
    {
        return $this->header('realm')
            ?? $this->header('variable_sip_auth_realm');
    }

    /**
     * Get the Sofia SIP profile name (e.g. internal, external).
     */
    public function profileName(): ?string
    {
        return $this->header('profile-name');
    }
}
