<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Illuminate\Support\Facades\Event;
use Modules\Security\Events\ObserveTrafficLogged;

/**
 * Unit and feature tests for ObserveTrafficLogged broadcast event.
 */
it('broadcasts ObserveTrafficLogged on the private security.alerts channel with correct name', function (): void {
    $payload = [
        'timestamp' => '2026-10-08 23:45:00',
        'stage' => 'bans',
        'stage_label' => 'Banned Attacker',
        'src_ip' => '10.254.254.2',
        'dst_ip' => '10.254.254.1',
        'proto' => 'UDP',
    ];

    $event = new ObserveTrafficLogged($payload);

    expect($event->broadcastAs())->toBe('ObserveTrafficLogged')
        ->and($event->broadcastOn()[0]->name)->toBe('private-security.alerts')
        ->and($event->event)->toBe($payload);
});

it('dispatches ObserveTrafficLogged event successfully', function (): void {
    Event::fake();

    ObserveTrafficLogged::dispatch(['src_ip' => '10.254.254.2']);

    Event::assertDispatched(ObserveTrafficLogged::class, function (ObserveTrafficLogged $event): bool {
        return $event->event['src_ip'] === '10.254.254.2';
    });
});
