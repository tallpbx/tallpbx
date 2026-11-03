<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Dashboard\DashboardStatsUpdated;
use App\Events\FreeSwitch\FreeSwitchEvent;
use Illuminate\Support\Facades\Log;

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
     * Last broadcast timestamp in milliseconds.
     */
    private static float $lastBroadcastMs = 0.0;

    /**
     * Reset throttle state (primarily for testing).
     */
    public static function resetThrottle(): void
    {
        self::$lastBroadcastMs = 0.0;
    }

    /**
     * Handle the FreeSWITCH event.
     */
    public function handle(FreeSwitchEvent $event): void
    {
        // Avoid broadcasting during tests unless specifically testing broadcasts
        if (app()->runningUnitTests() && ! config('broadcasting.test_broadcasts', false)) {
            return;
        }

        $throttleMs = (int) config('freeswitch.broadcasting.throttle_ms', 500);

        $nowMs = microtime(true) * 1000.0;

        if ($throttleMs > 0 && self::$lastBroadcastMs > 0.0 && ($nowMs - self::$lastBroadcastMs) < $throttleMs) {
            return;
        }

        try {
            DashboardStatsUpdated::dispatch($event->eventName);
            self::$lastBroadcastMs = $nowMs;
        } catch (\Throwable $e) {
            Log::warning('FreeSWITCH dashboard stats broadcast failed', [
                'event' => $event->eventName,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }
}
