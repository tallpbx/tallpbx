<?php

declare(strict_types=1);

namespace Modules\Security\Listeners;

use Illuminate\Auth\Events\Failed;
use Modules\Security\Contracts\SecurityIncidentServiceInterface;

/**
 * Event listener that captures failed authentication attempts.
 *
 * Listens to Laravel's Illuminate\Auth\Events\Failed event (Web admin and tenant user logins)
 * and forwards the client IP address to SecurityIncidentServiceInterface to track
 * brute-force attempts and trigger automated kernel bans.
 */
class LogFailedLoginListener
{
    /**
     * Create the event listener.
     *
     * @param  SecurityIncidentServiceInterface  $incidentService  Security incident ingestion service
     */
    public function __construct(
        private readonly SecurityIncidentServiceInterface $incidentService,
    ) {}

    /**
     * Handle the failed authentication event.
     *
     * Extracts client IP address and credentials context, then records the incident.
     *
     * @param  Failed  $event  Authentication failure event
     */
    public function handle(Failed $event): void
    {
        $ip = request()->ip() ?? '127.0.0.1';

        $username = $event->credentials['email']
            ?? $event->credentials['username']
            ?? 'unknown';

        $guard = $event->guard ?? 'unknown';

        $details = "User: {$username}, Guard: {$guard}";

        $this->incidentService->recordFailure(
            ip: $ip,
            vector: 'web_auth',
            details: $details,
        );
    }
}
