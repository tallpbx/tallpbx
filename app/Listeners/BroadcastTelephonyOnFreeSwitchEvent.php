<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\FreeSwitch\FreeSwitchEvent;
use App\Events\Telephony\TelephonyUpdated;
use Illuminate\Support\Facades\Log;

/**
 * Listener that broadcasts a TelephonyUpdated event whenever relevant FreeSWITCH ESL events occur.
 *
 * Catches channel lifecycle events (channel create, answer, hangup, destroy, bridge),
 * SIP registrations, conferences, call center events, and heartbeats, pushing
 * updates to connected Livewire telephony status tables via Laravel Reverb / Echo.
 */
class BroadcastTelephonyOnFreeSwitchEvent
{
    /**
     * Last broadcast timestamp in milliseconds per scope (tenant ID or 'global').
     *
     * @var array<string, float>
     */
    private static array $lastBroadcastTimes = [];

    /**
     * Reset throttle state (primarily for testing).
     */
    public static function resetThrottle(): void
    {
        self::$lastBroadcastTimes = [];
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

        $tenantId = $this->resolveTenantId($event);
        $scopeKey = $tenantId ?? 'global';

        $throttleMs = (int) config('freeswitch.broadcasting.throttle_ms', 500);

        $nowMs = microtime(true) * 1000.0;

        if ($throttleMs > 0 && isset(self::$lastBroadcastTimes[$scopeKey]) && ($nowMs - self::$lastBroadcastTimes[$scopeKey]) < $throttleMs) {
            return;
        }

        try {
            TelephonyUpdated::dispatch($tenantId, $event->eventName, $event->callUuid());
            self::$lastBroadcastTimes[$scopeKey] = $nowMs;
        } catch (\Throwable $e) {
            Log::warning('FreeSWITCH telephony broadcast failed', [
                'event' => $event->eventName,
                'call_uuid' => $event->callUuid(),
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Resolve the tenant ID from the FreeSWITCH event headers, if present.
     */
    private function resolveTenantId(FreeSwitchEvent $event): ?string
    {
        $explicitTenant = $event->header('variable_tenant_id')
            ?? $event->header('variable_sip_h_X-Tenant-ID');

        if ($explicitTenant !== null && $explicitTenant !== '') {
            return $explicitTenant;
        }

        // Try extracting from dialplan context (convention: tenant_{id}_{context})
        $context = $event->header('Caller-Context')
            ?? $event->header('Channel-Context')
            ?? $event->header('Context');

        if ($context !== null && preg_match('/^tenant_(\d+)_/i', $context, $m)) {
            return $m[1];
        }

        return null;
    }
}
