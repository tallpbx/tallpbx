<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| Security Alert Channel
|--------------------------------------------------------------------------
|
| The Security Command Center receives intruder, ban, and firewall alerts on
| this channel. It is private: only authenticated panel users holding the
| security.view permission may subscribe, so attacker IP telemetry and
| firewall change notices are never readable by unauthenticated WebSocket
| clients.
|
| The web guard is checked first to mirror the panel middleware
| (AdminAuthorize): during impersonation the tenant session is authoritative,
| while administrators normally authenticate through the admin guard.
|
*/
Broadcast::channel('security.alerts', function (Admin|User $user): bool {
    return $user->hasPermission('security.view');
}, ['guards' => ['web', 'admin']]);

/*
|--------------------------------------------------------------------------
| Tenant Telephony Channel
|--------------------------------------------------------------------------
|
| Real-time telephony push channel for runtime status tables (active calls,
| active conferences, SIP registrations, Sofia profile status, call center
| queues and agents, and the operator panel).
|
| Admins can subscribe to any tenant channel or the global channel.
| Tenant users are strictly isolated to their own tenant ID.
|
*/
Broadcast::channel('tenant.{tenantId}.telephony', function (Admin|User $user, string $tenantId): bool {
    if ($user instanceof Admin) {
        return true;
    }

    if ($tenantId === 'global' || ! ctype_digit($tenantId)) {
        return false;
    }

    return $user->isInTenant((int) $tenantId);
}, ['guards' => ['web', 'admin']]);
