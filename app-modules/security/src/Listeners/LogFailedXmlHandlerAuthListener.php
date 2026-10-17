<?php

declare(strict_types=1);

namespace Modules\Security\Listeners;

use App\Events\FreeSwitch\XmlHandlerFailedAuth;
use Modules\Security\Contracts\SecurityIncidentServiceInterface;

/**
 * Event listener that captures failed authentication attempts to the XML handler API.
 *
 * Listens for App\Events\FreeSwitch\XmlHandlerFailedAuth dispatched by XmlHandlerController
 * and forwards the offending IP to SecurityIncidentServiceInterface to track brute-force
 * token attacks and trigger automated kernel bans.
 */
class LogFailedXmlHandlerAuthListener
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
     * Handle the XML handler failed authentication event.
     *
     * @param  XmlHandlerFailedAuth  $event  XML handler authentication failure event
     */
    public function handle(XmlHandlerFailedAuth $event): void
    {
        $ip = trim($event->ip);
        if ($ip === '') {
            return;
        }

        $details = "Reason: {$event->reason}, Section: {$event->section}";

        $this->incidentService->recordFailure(
            ip: $ip,
            vector: 'xml_handler',
            details: $details,
        );
    }
}
