<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\FreeSwitch\FreeSwitchEvent;
use App\Events\Telephony\TelephonyUpdated;

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
     * Handle the FreeSWITCH event.
     */
    public function handle(FreeSwitchEvent $event): void
    {
        // Avoid broadcasting during tests unless specifically testing broadcasts
        if (app()->runningUnitTests() && ! config('broadcasting.test_broadcasts', false)) {
            return;
        }

        try {
            $tenantId = $this->resolveTenantId($event);

            TelephonyUpdated::dispatch($tenantId, $event->eventName, $event->callUuid());
        } catch (\Throwable $e) {
            // Silently ignore broadcast transport errors so the event loop is never interrupted
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
