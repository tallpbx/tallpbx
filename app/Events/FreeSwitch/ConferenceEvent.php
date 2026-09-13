<?php

declare(strict_types=1);

namespace App\Events\FreeSwitch;

/**
 * Dispatched when a CUSTOM conference event is received from FreeSWITCH.
 *
 * Listeners should inspect the Event-Subclass header to determine
 * the specific conference event type (e.g., conference-maintenance,
 * conference-event).
 */
class ConferenceEvent extends FreeSwitchEvent {}
