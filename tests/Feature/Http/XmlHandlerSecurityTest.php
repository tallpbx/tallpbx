<?php

declare(strict_types=1);

use App\Events\FreeSwitch\XmlHandlerFailedAuth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear('xml-handler:127.0.0.1');
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.throttle_enabled' => true,
        'freeswitch.xml_handler.throttle_limit' => 600,
        'freeswitch.xml_handler.dialplan_cache_ttl' => 0,
        'freeswitch.xml_handler.directory_cache_ttl' => 0,
    ]);
});

it('dispatches XmlHandlerFailedAuth event and logs security alert on missing token', function (): void {
    Event::fake([XmlHandlerFailedAuth::class]);
    Log::spy();

    config([
        'freeswitch.xml_handler.auth' => true,
        'freeswitch.xml_handler.token' => 'secure-token-xyz',
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'domain' => 'test.tallpbx.com',
    ]));

    $response->assertForbidden();

    Event::assertDispatched(XmlHandlerFailedAuth::class, function (XmlHandlerFailedAuth $event): bool {
        return $event->reason === 'missing_token'
            && $event->section === 'directory'
            && $event->ip === '127.0.0.1';
    });

    Log::shouldHaveReceived('alert')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'SECURITY ALERT: XML Handler authentication failed')
                && str_contains($message, 'no token provided');
        });
});

it('dispatches XmlHandlerFailedAuth event and logs security alert on token mismatch', function (): void {
    Event::fake([XmlHandlerFailedAuth::class]);
    Log::spy();

    config([
        'freeswitch.xml_handler.auth' => true,
        'freeswitch.xml_handler.token' => 'secret-expected-token',
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'dialplan',
        'token' => 'attacker-guessed-wrong-token',
    ]));

    $response->assertForbidden();

    Event::assertDispatched(XmlHandlerFailedAuth::class, function (XmlHandlerFailedAuth $event): bool {
        return $event->reason === 'token_mismatch'
            && $event->section === 'dialplan'
            && $event->ip === '127.0.0.1';
    });

    Log::shouldHaveReceived('alert')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'SECURITY ALERT: XML Handler authentication failed')
                && str_contains($message, 'token mismatch');
        });
});

it('does not dispatch XmlHandlerFailedAuth when authentication succeeds', function (): void {
    Event::fake([XmlHandlerFailedAuth::class]);

    config([
        'freeswitch.xml_handler.auth' => true,
        'freeswitch.xml_handler.token' => 'valid-secret-token',
    ]);

    $response = $this->get('/api/v1/xml-handler?'.http_build_query([
        'section' => 'directory',
        'token' => 'valid-secret-token',
    ]));

    $response->assertOk();
    Event::assertNotDispatched(XmlHandlerFailedAuth::class);
});

it('enforces rate limiting and returns 429 when threshold is exceeded', function (): void {
    config([
        'freeswitch.xml_handler.throttle_enabled' => true,
        'freeswitch.xml_handler.throttle_limit' => 3,
    ]);

    RateLimiter::clear('xml-handler:127.0.0.1');

    // 3 allowed requests
    $this->get('/api/v1/xml-handler?section=directory')->assertOk();
    $this->get('/api/v1/xml-handler?section=directory')->assertOk();
    $this->get('/api/v1/xml-handler?section=directory')->assertOk();

    // 4th request should exceed the limit and receive 429 Too Many Requests
    $this->get('/api/v1/xml-handler?section=directory')
        ->assertStatus(429);
});

it('bypasses rate limiting when throttle is disabled in configuration', function (): void {
    config([
        'freeswitch.xml_handler.throttle_enabled' => false,
        'freeswitch.xml_handler.throttle_limit' => 1,
    ]);

    RateLimiter::clear('xml-handler:127.0.0.1');

    $this->get('/api/v1/xml-handler?section=directory')->assertOk();
    $this->get('/api/v1/xml-handler?section=directory')->assertOk();
    $this->get('/api/v1/xml-handler?section=directory')->assertOk();
});
