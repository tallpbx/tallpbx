<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Marks a dialplan contributor whose XML guards or observes traffic
 * instead of routing it.
 *
 * Guard contributions (such as the SIP scanner detection conditions) appear
 * in the dialplan like any other contributor and keep their priority slot,
 * but they must never suppress the fail-closed no-route default: a context
 * with no routing rules still ends with an explicit NO_ROUTE_DESTINATION
 * hangup. The collector reports whether any non-guard contributor produced
 * XML so the builder can make that decision correctly.
 */
interface DialplanGuardXmlContributor extends DialplanXmlContributor
{
    //
}
