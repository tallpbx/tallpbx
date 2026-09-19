<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Illuminate\Broadcasting\PrivateChannel;
use Modules\Security\Events\FirewallRulesetUpdated;
use Modules\Security\Events\SecurityBanUpdated;
use Modules\Security\Events\SecurityIncidentLogged;

/**
 * Feature tests for the Security Command Center real-time alert channel.
 *
 * Ban, incident, and firewall events must travel over a PRIVATE channel so that
 * attacker IP telemetry, ban activity, and firewall change notices are only
 * delivered to signed-in panel users holding the security.view permission.
 */
beforeEach(function (): void {
    // Swap the forced phpunit "null" broadcasting driver for the reverb
    // (Pusher protocol) driver so the /broadcasting/auth endpoint performs real
    // channel authorization. The credentials are fakes: authorization responses
    // are signed locally with the secret and never reach a network.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
    ]);

    // Channel callbacks (and their "guards" options) register on whichever
    // driver is selected when the channels file runs. Boot already bound them
    // to the forced "null" driver, so re-run the file to bind the same
    // callbacks to the reverb driver the auth endpoint now resolves.
    require base_path('routes/channels.php');
});

/**
 * Build the subscribe request payload for the security alerts channel.
 *
 * The socket id mimics the "senderId.connectionId" format Reverb clients send.
 *
 * @return array<string, string>
 */
function securityAlertsSubscribePayload(): array
{
    return [
        'channel_name' => 'private-security.alerts',
        'socket_id' => '123456.789012',
    ];
}

it('broadcasts security alerts on the private security alerts channel', function (): void {
    $events = [
        new SecurityIncidentLogged('198.51.100.10', 'web_auth', 3),
        new SecurityBanUpdated('198.51.100.10', 'ban'),
        new FirewallRulesetUpdated('ui'),
    ];

    foreach ($events as $event) {
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1)
            ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
            ->and($channels[0]->name)->toBe('private-security.alerts');
    }
});

it('authorizes admins holding security.view to subscribe to security alerts', function (): void {
    $admin = grantAdminPermissions(permissions: ['security.view']);

    $this->actingAs($admin, 'admin')
        ->post('/broadcasting/auth', securityAlertsSubscribePayload())
        ->assertSuccessful()
        ->assertJsonStructure(['auth']);
});

it('rejects admins without the security.view permission from subscribing', function (): void {
    $admin = grantAdminPermissions(permissions: ['panel.dashboard.view']);

    $this->actingAs($admin, 'admin')
        ->post('/broadcasting/auth', securityAlertsSubscribePayload())
        ->assertForbidden();
});

it('rejects guests from subscribing to security alerts', function (): void {
    $this->post('/broadcasting/auth', securityAlertsSubscribePayload())
        ->assertForbidden();
});
