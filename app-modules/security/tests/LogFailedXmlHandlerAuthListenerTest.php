<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Events\FreeSwitch\XmlHandlerFailedAuth;
use Mockery;
use Modules\Security\Contracts\SecurityIncidentServiceInterface;

it('captures XmlHandlerFailedAuth event and forwards to SecurityIncidentService', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldReceive('recordFailure')
        ->once()
        ->with(
            '203.0.113.42',
            'xml_handler',
            'Reason: token_mismatch, Section: directory'
        );

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $event = new XmlHandlerFailedAuth(
        ip: '203.0.113.42',
        reason: 'token_mismatch',
        section: 'directory',
    );

    event($event);
});

it('discards XmlHandlerFailedAuth event when IP address is empty or whitespace', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldNotReceive('recordFailure');

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $event = new XmlHandlerFailedAuth(
        ip: '   ',
        reason: 'missing_token',
        section: 'dialplan',
    );

    event($event);
});
