<?php

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
