<?php

declare(strict_types=1);

namespace Modules\Security\Listeners;

use App\Events\FreeSwitch\CustomEvent;
use App\Events\FreeSwitch\SofiaFailedAuth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Security\Contracts\SecurityIncidentServiceInterface;

/**
 * Event listener that captures FreeSWITCH SIP authentication failures.
 *
 * Listens for Sofia SIP failed_auth events emitted via FreeSWITCH ESL
 * (unauthorized SIP REGISTER and INVITE attempts) and forwards offending
 * client IPs to SecurityIncidentServiceInterface to enforce kernel intrusion bans.
 *
 * Note: Since this listener implements ShouldQueue to protect ESL event loop throughput,
 * active queue workers must be running for the configured queue
 * (`freeswitch.esl.queue_name`, default 'default') to persist incidents and enforce bans.
 */
class LogFailedSipAuthListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The name of the queue the job should be sent to.
     */
    public ?string $queue = null;

    /**
     * Create the event listener.
     *
     * @param  SecurityIncidentServiceInterface  $incidentService  Security incident ingestion service
     */
    public function __construct(
        private readonly SecurityIncidentServiceInterface $incidentService,
    ) {
        $this->queue = (string) config('freeswitch.esl.queue_name', 'default');
    }

    /**
     * Get the name of the listener's queue.
     */
    public function viaQueue(): string
    {
        return (string) config('freeswitch.esl.queue_name', 'default');
    }

    /**
     * Handle incoming FreeSWITCH ESL custom and Sofia failed authentication events.
     *
     * Extracts the attacker's network IP, SIP username, realm, and profile,
     * then records the failure under the 'sip_auth' attack vector.
     *
     * @param  CustomEvent|SofiaFailedAuth  $event  FreeSWITCH event
     */
    public function handle(CustomEvent $event): void
    {
        // Only process sofia::failed_auth events
        if (! ($event instanceof SofiaFailedAuth) && $event->header('Event-Subclass') !== 'sofia::failed_auth') {
            return;
        }

        $ip = ($event instanceof SofiaFailedAuth)
            ? $event->networkIp()
            : ($event->header('network-ip')
                ?? $event->header('variable_sip_network_ip')
                ?? $event->header('Caller-Network-Addr'));

        if ($ip === null || trim($ip) === '') {
            return;
        }

        $user = ($event instanceof SofiaFailedAuth)
            ? ($event->user() ?? 'unknown')
            : ($event->header('user') ?? $event->header('variable_sip_auth_username') ?? 'unknown');

        $realm = ($event instanceof SofiaFailedAuth)
            ? ($event->realm() ?? 'unknown')
            : ($event->header('realm') ?? $event->header('variable_sip_auth_realm') ?? 'unknown');

        $profile = ($event instanceof SofiaFailedAuth)
            ? ($event->profileName() ?? 'unknown')
            : ($event->header('profile-name') ?? 'unknown');

        $details = "SIP User: {$user}, Realm: {$realm}, Profile: {$profile}";

        $this->incidentService->recordFailure(
            ip: trim($ip),
            vector: 'sip_auth',
            details: $details,
        );
    }
}
