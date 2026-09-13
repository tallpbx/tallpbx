<?php

declare(strict_types=1);

namespace App\Events\FreeSwitch;

/**
 * Dispatched when a CUSTOM callcenter event is received from FreeSWITCH.
 *
 * Listeners should inspect the Event-Subclass header to determine
 * the specific call center event type (e.g., cc_queue, cc_agent).
 */
class CallCenterEvent extends FreeSwitchEvent {}
