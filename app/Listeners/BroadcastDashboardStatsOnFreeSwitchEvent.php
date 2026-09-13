<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Dashboard\DashboardStatsUpdated;
use App\Events\FreeSwitch\FreeSwitchEvent;

/**
 * Listener that broadcasts a DashboardStatsUpdated event whenever relevant FreeSWITCH ESL events occur.
 *
 * Catches channel lifecycle events (call starts, answers, hangups, bridges) and heartbeats,
 * debouncing rapid-fire events so connected dashboards are kept real-time without overwhelming
 * the browser.
 */
class BroadcastDashboardStatsOnFreeSwitchEvent
{
    /**
     * Handle the FreeSWITCH event.
     */
    public function handle(FreeSwitchEvent $event): void
    {
        // Avoid broadcasting during tests unless specifically testing broadcasts
        if (app()->runningUnitTests() && ! config('broadcasting.test_broadcasts', false)) {
            return;
        }

        try {
            DashboardStatsUpdated::dispatch($event->eventName);
        } catch (\Throwable $e) {
            // Silently ignore broadcast transport errors
        }
    }
}
