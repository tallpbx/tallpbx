<?php

declare(strict_types=1);

namespace Modules\Security\Contracts;

/**
 * Contract for recording security incidents and access failures.
 *
 * Ingests failure events from web authentication, FreeSWITCH ESL SIP authentication,
 * and SSH logins, evaluating failure thresholds to trigger automated kernel-level bans.
 */
interface SecurityIncidentServiceInterface
{
    /**
     * Record an authentication or access failure from an incoming IP address.
     *
     * @param  string  $ip  Source IPv4 or IPv6 address of the failed request
     * @param  string  $vector  Attack vector: 'web_auth', 'sip_auth', or 'ssh'
     * @param  string  $details  Contextual metadata (e.g. attempted username or SIP realm)
     */
    public function recordFailure(string $ip, string $vector, string $details = ''): void;
}
